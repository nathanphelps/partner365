<?php

namespace App\Jobs;

use App\Enums\SweepEntryAction;
use App\Enums\SweepRunStatus;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Models\SensitivityLabel;
use App\Models\SharePointSite;
use App\Services\BridgeClient;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeConfigException;
use App\Services\Exceptions\BridgeException;
use App\Services\Exceptions\BridgeLabelConflictException;
use App\Services\Exceptions\BridgeNetworkException;
use App\Services\Exceptions\BridgeSiteNotFoundException;
use App\Services\Exceptions\BridgeThrottleException;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ApplySiteLabelJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public int $timeout = 120;

    /**
     * Number of auth/certificate failures within a run that triggers an abort.
     * Documented in docs/admin/sensitivity-labels-sidecar-setup.md Troubleshooting.
     */
    private const SYSTEMIC_FAILURE_THRESHOLD = 3;

    /** How long the systemic-failure counter lives in cache (long enough to span any single run). */
    private const SYSTEMIC_FAILURE_TTL_HOURS = 6;

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

        if (! $run || $run->status === SweepRunStatus::Aborted) {
            $this->writeEntry(SweepEntryAction::SkippedAborted);

            return;
        }

        try {
            $bridge->setLabel($this->siteUrl, $this->labelId, overwrite: false);
            $this->writeEntry(SweepEntryAction::Applied);
            $this->updateSiteSensitivityLabel();

            return;
        } catch (BridgeLabelConflictException) {
            $this->writeEntry(SweepEntryAction::SkippedLabeled);

            return;
        } catch (BridgeSiteNotFoundException $e) {
            $this->writeEntry(SweepEntryAction::Failed, errorCode: 'not_found', errorMessage: $e->getMessage());

            return;
        } catch (BridgeThrottleException|BridgeNetworkException $e) {
            // Let the queue worker retry transient failures per $tries/$backoff.
            // Swallowing here would mark the site as permanently failed.
            throw $e;
        } catch (BridgeAuthException|BridgeConfigException $e) {
            // Auth/cert errors are the same root cause for every site — count toward abort.
            $this->writeEntry(
                SweepEntryAction::Failed,
                errorCode: $e->errorCode ?? 'auth',
                errorMessage: $e->getMessage(),
            );
            $this->incrementSystemicCounter();

            return;
        } catch (BridgeException $e) {
            if ($this->attempts() >= $this->tries) {
                $this->writeEntry(SweepEntryAction::Failed, errorCode: $e->errorCode ?? 'unknown', errorMessage: $e->getMessage());

                return;
            }
            throw $e;
        }
    }

    /**
     * Called by Laravel only when the job throws out of handle() on the final attempt.
     * Idempotent against entries already written by handle() — checked via site_url + run_id.
     */
    public function failed(\Throwable $e): void
    {
        $existing = LabelSweepRunEntry::where('label_sweep_run_id', $this->runId)
            ->where('site_url', $this->siteUrl)
            ->exists();

        if ($existing) {
            return;
        }

        $this->writeEntry(
            SweepEntryAction::Failed,
            errorCode: $e instanceof BridgeException ? ($e->errorCode ?? 'unknown') : 'unknown',
            errorMessage: $e->getMessage(),
        );
    }

    private function writeEntry(SweepEntryAction $action, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $carriesLabel = $action === SweepEntryAction::Applied || $action === SweepEntryAction::SkippedLabeled;

        LabelSweepRunEntry::create([
            'label_sweep_run_id' => $this->runId,
            'site_url' => $this->siteUrl,
            'site_title' => $this->siteTitle,
            'action' => $action,
            'label_id' => $carriesLabel ? $this->labelId : null,
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
            // Bridge already wrote the label to SharePoint; we just can't mirror locally
            // because the label GUID is not in our catalog. Run a label catalog sync.
            Log::warning('Applied label GUID is not in local catalog; SharePointSite row not updated locally', [
                'run_id' => $this->runId,
                'site_url' => $this->siteUrl,
                'label_id' => $this->labelId,
            ]);

            return;
        }

        SharePointSite::where('url', $this->siteUrl)
            ->update(['sensitivity_label_id' => $label->id]);
    }

    private function incrementSystemicCounter(): void
    {
        $key = "sweep:{$this->runId}:systemic_failures";

        // Seed the key so drivers without atomic-increment-on-missing (e.g. file) work.
        Cache::add($key, 0, now()->addHours(self::SYSTEMIC_FAILURE_TTL_HOURS));
        $count = Cache::increment($key);

        if ($count === false) {
            Log::error('Cache driver does not support atomic increment; systemic-failure abort gate is disabled', [
                'run_id' => $this->runId,
            ]);

            return;
        }

        if ($count >= self::SYSTEMIC_FAILURE_THRESHOLD) {
            AbortSweepRunJob::dispatch($this->runId);
        }
    }
}
