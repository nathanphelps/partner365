<?php

namespace App\Jobs;

use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Models\SensitivityLabel;
use App\Models\SiteSensitivityLabel;
use App\Services\BridgeClient;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeConfigException;
use App\Services\Exceptions\BridgeException;
use App\Services\Exceptions\BridgeLabelConflictException;
use App\Services\Exceptions\BridgeNetworkException;
use App\Services\Exceptions\BridgeSiteNotFoundException;
use App\Services\Exceptions\BridgeThrottleException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ApplySiteLabelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(
        public readonly int $runId,
        public readonly string $siteUrl,
        public readonly string $siteTitle,
        public readonly string $labelId,
        public readonly ?int $matchedRuleId,
    ) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(BridgeClient $bridge): void
    {
        $run = LabelSweepRun::find($this->runId);

        if (! $run || $run->status === 'aborted') {
            $this->writeEntry('skipped_aborted');

            return;
        }

        try {
            $bridge->setLabel($this->siteUrl, $this->labelId, overwrite: false);
            $this->writeEntry('applied');
            $this->updateSiteSensitivityLabel();

            return;
        } catch (BridgeLabelConflictException) {
            $this->writeEntry('skipped_labeled');

            return;
        } catch (BridgeSiteNotFoundException $e) {
            $this->writeEntry('failed', errorCode: 'not_found', errorMessage: $e->getMessage());

            return;
        } catch (BridgeThrottleException|BridgeNetworkException $e) {
            throw $e;
        } catch (BridgeAuthException|BridgeConfigException $e) {
            $this->writeEntry(
                'failed',
                errorCode: $e->errorCode ?? 'auth',
                errorMessage: $e->getMessage(),
            );
            $this->incrementSystemicCounter();

            return;
        } catch (BridgeException $e) {
            if ($this->attempts() >= $this->tries) {
                $this->writeEntry('failed', errorCode: $e->errorCode ?? 'unknown', errorMessage: $e->getMessage());

                return;
            }
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->writeEntry(
            'failed',
            errorCode: $e instanceof BridgeException ? ($e->errorCode ?? 'unknown') : 'unknown',
            errorMessage: $e->getMessage(),
        );
    }

    private function writeEntry(string $action, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        LabelSweepRunEntry::create([
            'label_sweep_run_id' => $this->runId,
            'site_url' => $this->siteUrl,
            'site_title' => $this->siteTitle,
            'action' => $action,
            'label_id' => $action === 'applied' || $action === 'skipped_labeled' ? $this->labelId : null,
            'matched_rule_id' => $this->matchedRuleId,
            'error_message' => $errorMessage,
            'error_code' => $errorCode,
            'processed_at' => now(),
        ]);
    }

    private function updateSiteSensitivityLabel(): void
    {
        $label = SensitivityLabel::where('label_id', $this->labelId)->first();

        if (! $label) {
            return;
        }

        SiteSensitivityLabel::where('site_url', $this->siteUrl)
            ->update(['sensitivity_label_id' => $label->id]);
    }

    private function incrementSystemicCounter(): void
    {
        $key = "sweep:{$this->runId}:systemic_failures";
        $count = Cache::increment($key);
        Cache::put($key, $count, now()->addHours(6));

        if ($count >= 3) {
            AbortSweepRunJob::dispatch($this->runId);
        }
    }
}
