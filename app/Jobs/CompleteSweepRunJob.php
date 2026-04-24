<?php

namespace App\Jobs;

use App\Enums\ActivityAction;
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

    public function __construct(public readonly int $runId) {}

    public function handle(): void
    {
        $run = LabelSweepRun::find($this->runId);

        if (! $run) {
            return;
        }

        $counts = LabelSweepRunEntry::where('label_sweep_run_id', $run->id)
            ->selectRaw('action, count(*) as c')
            ->groupBy('action')
            ->pluck('c', 'action')
            ->all();

        $applied = (int) ($counts['applied'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $skippedLabeled = (int) ($counts['skipped_labeled'] ?? 0);
        $skippedExcluded = (int) ($counts['skipped_excluded'] ?? 0);

        $status = match (true) {
            $run->status === 'aborted' => 'aborted',
            $failed > 0 => 'partial_failure',
            default => 'success',
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
                'status' => $status,
                'applied' => $applied,
                'failed' => $failed,
            ],
        );

        $this->trimHistory();
    }

    private function trimHistory(): void
    {
        $keep = 500;
        $ids = LabelSweepRun::orderByDesc('started_at')
            ->skip($keep)
            ->take(10_000)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            LabelSweepRun::whereIn('id', $ids)->delete();
        }
    }
}
