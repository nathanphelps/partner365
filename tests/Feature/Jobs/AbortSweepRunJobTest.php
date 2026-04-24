<?php

use App\Enums\UserRole;
use App\Jobs\AbortSweepRunJob;
use App\Models\ActivityLog;
use App\Models\LabelSweepRun;
use App\Models\User;
use App\Notifications\SweepAbortedNotification;
use Illuminate\Support\Facades\Notification;

test('marks run aborted with error message', function () {
    Notification::fake();
    $run = LabelSweepRun::factory()->create(['status' => 'running']);

    (new AbortSweepRunJob($run->id))->handle();

    $run->refresh();
    expect($run->status)->toBe('aborted');
    expect($run->error_message)->toContain('systemic');
    expect($run->completed_at)->not->toBeNull();
});

test('logs sweep_aborted activity entry', function () {
    Notification::fake();
    $run = LabelSweepRun::factory()->create(['status' => 'running']);

    (new AbortSweepRunJob($run->id))->handle();

    expect(ActivityLog::where('action', 'sweep_aborted')->count())->toBe(1);
});

test('notifies admin users', function () {
    Notification::fake();
    User::factory()->create(['role' => UserRole::Admin]);
    User::factory()->create(['role' => UserRole::Operator]);
    $run = LabelSweepRun::factory()->create(['status' => 'running']);

    (new AbortSweepRunJob($run->id))->handle();

    Notification::assertSentTimes(SweepAbortedNotification::class, 1);
});

test('idempotent on already-aborted run', function () {
    Notification::fake();
    $run = LabelSweepRun::factory()->create(['status' => 'aborted', 'error_message' => 'old']);

    (new AbortSweepRunJob($run->id))->handle();

    $run->refresh();
    expect($run->error_message)->toBe('old');
    Notification::assertNothingSent();
});
