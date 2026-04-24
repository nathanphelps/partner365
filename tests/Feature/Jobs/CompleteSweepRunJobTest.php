<?php

use App\Jobs\CompleteSweepRunJob;
use App\Models\ActivityLog;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;

test('aggregates entry counts onto run', function () {
    $run = LabelSweepRun::factory()->create(['status' => 'running']);
    LabelSweepRunEntry::factory()->count(5)->create(['label_sweep_run_id' => $run->id, 'action' => 'applied']);
    LabelSweepRunEntry::factory()->count(2)->create(['label_sweep_run_id' => $run->id, 'action' => 'failed']);

    (new CompleteSweepRunJob($run->id))->handle();

    $run->refresh();
    expect($run->applied)->toBe(5);
    expect($run->failed)->toBe(2);
    expect($run->completed_at)->not->toBeNull();
});

test('sets status success when no failures', function () {
    $run = LabelSweepRun::factory()->create(['status' => 'running']);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => 'applied']);

    (new CompleteSweepRunJob($run->id))->handle();

    expect($run->fresh()->status)->toBe('success');
});

test('sets status partial_failure when any failures present', function () {
    $run = LabelSweepRun::factory()->create(['status' => 'running']);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => 'applied']);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => 'failed']);

    (new CompleteSweepRunJob($run->id))->handle();

    expect($run->fresh()->status)->toBe('partial_failure');
});

test('preserves aborted status', function () {
    $run = LabelSweepRun::factory()->create(['status' => 'aborted', 'error_message' => 'x']);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => 'applied']);

    (new CompleteSweepRunJob($run->id))->handle();

    expect($run->fresh()->status)->toBe('aborted');
});

test('logs sweep_ran activity with summary', function () {
    $run = LabelSweepRun::factory()->create(['status' => 'running']);
    LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'action' => 'applied']);

    (new CompleteSweepRunJob($run->id))->handle();

    $log = ActivityLog::where('action', 'sweep_ran')->first();
    expect($log)->not->toBeNull();
    expect($log->details['run_id'])->toBe($run->id);
    expect($log->details['applied'])->toBe(1);
});

test('trims run history beyond 500 entries', function () {
    // Create 502 runs (500 old + 1 more old + 1 that we complete).
    LabelSweepRun::factory()->count(501)->create(['status' => 'success', 'started_at' => now()->subDays(1)]);
    $run = LabelSweepRun::factory()->create(['status' => 'running', 'started_at' => now()]);

    (new CompleteSweepRunJob($run->id))->handle();

    expect(LabelSweepRun::count())->toBe(500);
});
