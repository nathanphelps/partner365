<?php

namespace App\Console\Commands;

use App\Jobs\ApplySiteLabelJob;
use App\Jobs\CompleteSweepRunJob;
use App\Models\LabelRule;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Models\Setting;
use App\Models\SharePointSite;
use App\Models\SiteExclusion;
use App\Services\BridgeClient;
use App\Services\Exceptions\BridgeException;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class SensitivitySweepCommand extends Command
{
    protected $signature = 'sensitivity:sweep {--force : Bypass the interval guard} {--dry-run : Enumerate and classify but do not dispatch apply jobs}';

    protected $description = 'Run the sensitivity-label sweep across tracked SharePoint sites';

    public function handle(BridgeClient $bridge): int
    {
        if (! (bool) Setting::get('sensitivity_sweep', 'enabled', false)) {
            $this->info('Sweeps are disabled (sensitivity_sweep.enabled=false).');

            return Command::SUCCESS;
        }

        $defaultLabel = (string) Setting::get('sensitivity_sweep', 'default_label_id', '');
        if ($defaultLabel === '') {
            $this->info('Default label not configured; skipping.');

            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! $this->intervalElapsed()) {
            $this->info('Interval not elapsed; skipping. Use --force to override.');

            return Command::SUCCESS;
        }

        $run = LabelSweepRun::create([
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $bridge->health();
        } catch (BridgeException $e) {
            $run->update([
                'status' => 'failed',
                'error_message' => 'Bridge pre-flight failed: '.$e->getMessage(),
                'completed_at' => now(),
            ]);
            $this->error('Bridge unreachable: '.$e->getMessage());

            // Non-zero exit propagates to schedule:run monitors and CI.
            return Command::FAILURE;
        }

        $exclusions = SiteExclusion::pluck('pattern')->all();
        $rules = LabelRule::orderBy('priority')->get();

        $candidates = SharePointSite::query()
            ->where(function ($q) {
                $q->where('url', 'like', '%/sites/%')
                    ->orWhere('url', 'like', '%/teams/%');
            })
            ->get();

        $scanned = 0;
        $alreadyLabeled = 0;
        $skippedExcluded = 0;
        /** @var list<ApplySiteLabelJob> $pendingJobs */
        $pendingJobs = [];

        foreach ($candidates as $site) {
            $scanned++;

            if ($this->isExcluded($site->url, $exclusions)) {
                $skippedExcluded++;
                LabelSweepRunEntry::create([
                    'label_sweep_run_id' => $run->id,
                    'site_url' => $site->url,
                    'site_title' => $site->display_name,
                    'action' => 'skipped_excluded',
                    'processed_at' => now(),
                ]);

                // Do NOT delete the SharePointSite row. The row may have cascading
                // relations (sharepoint_site_permissions) that represent audit-critical
                // guest-access history. Exclusion is a sweep-time decision only.
                continue;
            }

            if ($site->sensitivity_label_id !== null) {
                $alreadyLabeled++;
                LabelSweepRunEntry::create([
                    'label_sweep_run_id' => $run->id,
                    'site_url' => $site->url,
                    'site_title' => $site->display_name,
                    'action' => 'skipped_labeled',
                    'processed_at' => now(),
                ]);

                continue;
            }

            [$labelId, $matchedRuleId] = $this->resolveLabel($site->display_name, $rules, $defaultLabel);

            if ($this->option('dry-run')) {
                LabelSweepRunEntry::create([
                    'label_sweep_run_id' => $run->id,
                    'site_url' => $site->url,
                    'site_title' => $site->display_name,
                    'action' => 'applied',
                    'label_id' => $labelId,
                    'matched_rule_id' => $matchedRuleId,
                    'error_message' => '[dry-run] would apply',
                    'processed_at' => now(),
                ]);

                continue;
            }

            $pendingJobs[] = new ApplySiteLabelJob(
                $run->id,
                $site->url,
                $site->display_name,
                $labelId,
                $matchedRuleId,
            );
        }

        $run->update([
            'total_scanned' => $scanned,
            'already_labeled' => $alreadyLabeled,
            'skipped_excluded' => $skippedExcluded,
        ]);

        $runId = $run->id;

        if (count($pendingJobs) === 0) {
            // Nothing to dispatch — finalize immediately so dashboards reflect the run.
            CompleteSweepRunJob::dispatchSync($runId);
        } else {
            // Bus::batch completion fires after every apply job settles (success OR failed),
            // so run finalization cannot race against in-flight retries.
            Bus::batch($pendingJobs)
                ->name("sensitivity-sweep-{$runId}")
                ->allowFailures()
                ->finally(function (Batch $batch) use ($runId) {
                    CompleteSweepRunJob::dispatch($runId);
                })
                ->dispatch();
        }

        $this->info("Sweep run #{$runId} dispatched. Scanned {$scanned}, excluded {$skippedExcluded}, already labeled {$alreadyLabeled}.");

        return Command::SUCCESS;
    }

    private function intervalElapsed(): bool
    {
        $interval = max(1, (int) Setting::get('sensitivity_sweep', 'interval_minutes', 90));
        $last = LabelSweepRun::latest('started_at')->first();

        if (! $last) {
            return true;
        }

        return $last->started_at->lt(now()->subMinutes($interval));
    }

    /** @param  string[]  $exclusions */
    private function isExcluded(string $url, array $exclusions): bool
    {
        foreach ($exclusions as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (stripos($url, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, LabelRule>  $rules
     * @return array{0: string, 1: ?int}
     */
    private function resolveLabel(string $title, $rules, string $defaultLabel): array
    {
        foreach ($rules as $rule) {
            if ($rule->prefix === '') {
                continue;
            }
            if (stripos($title, $rule->prefix) === 0) {
                return [$rule->label_id, $rule->id];
            }
        }

        return [$defaultLabel, null];
    }
}
