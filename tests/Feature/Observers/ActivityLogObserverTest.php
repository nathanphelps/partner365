<?php

use App\Enums\ActivityAction;
use App\Jobs\ForwardToSyslog;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Queue;

test('creating an activity log dispatches ForwardToSyslog job', function () {
    Queue::fake();

    ActivityLog::create([
        'user_id' => null,
        'action' => ActivityAction::SyncCompleted,
        'details' => ['type' => 'partners'],
        'created_at' => now(),
    ]);

    Queue::assertPushed(ForwardToSyslog::class, function ($job) {
        return $job->activityLog->action === ActivityAction::SyncCompleted;
    });
});
