<?php

use App\Enums\SweepEntryAction;
use App\Enums\SweepRunStatus;
use App\Jobs\CompleteSweepRunJob;
use App\Models\ActivityLog;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;

test('aggregates entry counts onto run', function () {
    $run = LabelSweepRun::factory()->create(['status' => SweepRunStatus::Running]);
    LabelSweepRunEntry::factory()->count(5)->create(['label_sweep_run_id' => $run->id, 'action' => SweepEntryAction::Applied]);
    LabelSweepRunEntry::factory()->count(2)->create(['label_sweep_run_id' => $run->id, 'action' => SweepEntryAction::Failed]);

    (new CompleteSweepRunJob($run->id))->handle();

    $run->refresh();
    expect($run->applied)->toBe(5);
    expect($run->failed)->toBe(2);
    expect($run->completed_at)->not->toBeNull();
});

test('sets status success when no failures', function () {
    $run = LabelSweepRun::factory()->create(['status' => SweepRunStatus::Running]);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => SweepEntryAction::Applied]);

    (new CompleteSweepRunJob($run->id))->handle();

    expect($run->fresh()->status)->toBe(SweepRunStatus::Success);
});

test('sets status partial_failure when any failures present', function () {
    $run = LabelSweepRun::factory()->create(['status' => SweepRunStatus::Running]);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => SweepEntryAction::Applied]);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => SweepEntryAction::Failed]);

    (new CompleteSweepRunJob($run->id))->handle();

    expect($run->fresh()->status)->toBe(SweepRunStatus::PartialFailure);
});

test('preserves aborted status', function () {
    $run = LabelSweepRun::factory()->create(['status' => SweepRunStatus::Aborted, 'error_message' => 'x']);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => SweepEntryAction::Applied]);

    (new CompleteSweepRunJob($run->id))->handle();

    expect($run->fresh()->status)->toBe(SweepRunStatus::Aborted);
});

test('logs sweep_ran activity with summary', function () {
    $run = LabelSweepRun::factory()->create(['status' => SweepRunStatus::Running]);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => SweepEntryAction::Applied]);

    (new CompleteSweepRunJob($run->id))->handle();

    $log = ActivityLog::where('action', 'sweep_ran')->first();
    expect($log)->not->toBeNull();
    expect($log->details['run_id'])->toBe($run->id);
    expect($log->details['applied'])->toBe(1);
});

test('preserves already_labeled + skipped_excluded counters when entries have none', function () {
    // The command pre-seeds these before dispatching jobs; if no per-entry `skipped_*`
    // rows exist (because the apply-job path never writes them), CompleteSweepRunJob
    // must keep the pre-seeded values.
    $run = LabelSweepRun::factory()->create([
        'status' => SweepRunStatus::Running,
        'already_labeled' => 7,
        'skipped_excluded' => 3,
    ]);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => SweepEntryAction::Applied]);

    (new CompleteSweepRunJob($run->id))->handle();

    $run->refresh();
    expect($run->already_labeled)->toBe(7);
    expect($run->skipped_excluded)->toBe(3);
});

test('trims run history beyond 500 entries', function () {
    LabelSweepRun::factory()->count(501)->create(['status' => SweepRunStatus::Success, 'started_at' => now()->subDays(1)]);
    $run = LabelSweepRun::factory()->create(['status' => SweepRunStatus::Running, 'started_at' => now()]);

    (new CompleteSweepRunJob($run->id))->handle();

    expect(LabelSweepRun::count())->toBe(500);
});

test('missing run logs warning and returns gracefully', function () {
    \Illuminate\Support\Facades\Log::spy();

    (new CompleteSweepRunJob(999_999))->handle();

    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->with(\Mockery::pattern('/run not found/'), \Mockery::any())
        ->once();
});
