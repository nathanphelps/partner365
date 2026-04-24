<?php

use App\Enums\UserRole;
use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use App\Models\User;

test('any authenticated approved user can view history index', function () {
    LabelSweepRun::factory()->count(3)->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer, 'approved_at' => now(), 'email_verified_at' => now()]))
        ->get(route('sensitivity-labels.sweep.history'))
        ->assertInertia(fn ($p) => $p->component('sensitivity-labels/Sweep/History')->has('runs.data', 3)
        );
});

test('history index orders by started_at desc by default', function () {
    $older = LabelSweepRun::factory()->create(['started_at' => now()->subDays(2)]);
    $newer = LabelSweepRun::factory()->create(['started_at' => now()->subHours(1)]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer, 'approved_at' => now(), 'email_verified_at' => now()]))
        ->get(route('sensitivity-labels.sweep.history'))
        ->assertInertia(fn ($p) => $p->where('runs.data.0.id', $newer->id)
            ->where('runs.data.1.id', $older->id)
        );
});

test('history detail includes entries in chronological order', function () {
    $run = LabelSweepRun::factory()->create();
    $second = LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'processed_at' => now()]);
    $first = LabelSweepRunEntry::factory()->create(['label_sweep_run_id' => $run->id, 'processed_at' => now()->subMinutes(5)]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer, 'approved_at' => now(), 'email_verified_at' => now()]))
        ->get(route('sensitivity-labels.sweep.history.show', $run))
        ->assertInertia(fn ($p) => $p->component('sensitivity-labels/Sweep/HistoryDetail')
            ->where('run.id', $run->id)
            ->where('entries.0.id', $first->id)
            ->where('entries.1.id', $second->id)
        );
});

test('unauthenticated user redirected from history', function () {
    $this->get(route('sensitivity-labels.sweep.history'))->assertRedirect(route('login'));
});
