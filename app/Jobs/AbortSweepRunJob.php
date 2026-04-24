<?php

namespace App\Jobs;

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Models\LabelSweepRun;
use App\Models\User;
use App\Notifications\SweepAbortedNotification;
use App\Services\ActivityLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class AbortSweepRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $runId) {}

    public function handle(): void
    {
        $run = LabelSweepRun::find($this->runId);

        if (! $run || $run->status === 'aborted') {
            return;
        }

        $run->update([
            'status' => 'aborted',
            'error_message' => 'Aborted after 3 systemic failures (auth/certificate). See run entries for details.',
            'completed_at' => now(),
        ]);

        app(ActivityLogService::class)->logSystem(
            ActivityAction::SweepAborted,
            subject: $run,
            details: ['run_id' => $run->id, 'error_message' => $run->error_message],
        );

        $admins = User::where('role', UserRole::Admin->value)->get();
        Notification::send($admins, new SweepAbortedNotification($run));
    }
}
