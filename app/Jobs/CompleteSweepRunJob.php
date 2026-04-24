<?php

namespace App\Jobs;

use App\Enums\ActivityAction;
use App\Enums\SweepEntryAction;
use App\Enums\SweepRunStatus;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Services\ActivityLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CompleteSweepRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Maximum sweep runs to retain; older runs are trimmed after each completion. */
    private const RETENTION_LIMIT = 500;

    public function __construct(public readonly int $runId) {}

    public function handle(): void
    {
        $run = LabelSweepRun::find($this->runId);

        if (! $run) {
            \Log::warning('CompleteSweepRunJob: run not found (likely trimmed)', [
                'run_id' => $this->runId,
            ]);

            return;
        }

        $counts = LabelSweepRunEntry::where('label_sweep_run_id', $run->id)
            ->selectRaw('action, count(*) as c')
            ->groupBy('action')
            ->pluck('c', 'action')
            ->all();

        $applied = (int) ($counts[SweepEntryAction::Applied->value] ?? 0);
        $failed = (int) ($counts[SweepEntryAction::Failed->value] ?? 0);
        $skippedLabeled = (int) ($counts[SweepEntryAction::SkippedLabeled->value] ?? 0);
        $skippedExcluded = (int) ($counts[SweepEntryAction::SkippedExcluded->value] ?? 0);

        $status = match (true) {
            // Aborted runs stay aborted — do not promote to partial_failure or success.
            $run->status === SweepRunStatus::Aborted => SweepRunStatus::Aborted,
            $failed > 0 => SweepRunStatus::PartialFailure,
            default => SweepRunStatus::Success,
        };

        $run->update([
            'applied' => $applied,
            'failed' => $failed,
            'already_labeled' => $skippedLabeled ?: $run->already_labeled,
            'skipped_excluded' => $skippedExcluded ?: $run->skipped_excluded,
            'status' => $status,
            'completed_at' => now(),
        ]);

        app(ActivityLogService::class)->logSystem(
            ActivityAction::SweepRan,
            subject: $run,
            details: [
                'run_id' => $run->id,
                'status' => $status->value,
                'applied' => $applied,
                'failed' => $failed,
            ],
        );

        $this->trimHistory();
    }

    private function trimHistory(): void
    {
        $ids = LabelSweepRun::orderByDesc('started_at')
            ->skip(self::RETENTION_LIMIT)
            ->take(10_000)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            LabelSweepRun::whereIn('id', $ids)->delete();
        }
    }
}
