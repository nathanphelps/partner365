<?php

use App\Models\SyncLog;

test('SyncLog can be created with required fields', function () {
    $log = SyncLog::create([
        'type' => 'partners',
        'status' => 'running',
        'started_at' => now(),
    ]);

    expect($log->type)->toBe('partners');
    expect($log->status)->toBe('running');
});

test('scopeRecent returns limited results in descending order', function () {
    SyncLog::factory()->count(15)->create(['type' => 'partners']);

    $recent = SyncLog::recent(10)->get();
    expect($recent)->toHaveCount(10);
    expect($recent->first()->started_at->gte($recent->last()->started_at))->toBeTrue();
});

test('scopeByType filters by sync type', function () {
    SyncLog::factory()->count(3)->create(['type' => 'partners']);
    SyncLog::factory()->count(2)->create(['type' => 'guests']);

    expect(SyncLog::byType('partners')->count())->toBe(3);
    expect(SyncLog::byType('guests')->count())->toBe(2);
});
