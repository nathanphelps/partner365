<?php

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\SyncLog;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'approved_at' => now(),
    ]);
});

test('admin can view sync settings page', function () {
    $this->actingAs($this->admin)
        ->get('/admin/sync')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Sync'));
});

test('sync settings page includes intervals and recent logs', function () {
    Setting::set('sync', 'partners_interval_minutes', '30');
    SyncLog::factory()->count(3)->create(['type' => 'partners']);
    SyncLog::factory()->count(2)->create(['type' => 'guests']);

    $this->actingAs($this->admin)
        ->get('/admin/sync')
        ->assertInertia(fn ($page) => $page
            ->where('intervals.partners_interval_minutes', '30')
            ->has('logs.partners', 3)
            ->has('logs.guests', 2)
        );
});

test('admin can update sync intervals', function () {
    $this->actingAs($this->admin)
        ->put('/admin/sync', [
            'partners_interval_minutes' => 30,
            'guests_interval_minutes' => 60,
        ])
        ->assertRedirect();

    expect(Setting::get('sync', 'partners_interval_minutes'))->toBe('30');
    expect(Setting::get('sync', 'guests_interval_minutes'))->toBe('60');
});

test('admin can trigger a manual sync', function () {
    $this->actingAs($this->admin)
        ->post('/admin/sync/partners/run')
        ->assertOk()
        ->assertJson(['message' => 'Sync started.']);
});

test('trigger sync rejects invalid type', function () {
    $this->actingAs($this->admin)
        ->post('/admin/sync/invalid/run')
        ->assertNotFound();
});

test('sync interval validation', function () {
    $this->actingAs($this->admin)
        ->put('/admin/sync', [
            'partners_interval_minutes' => 0,
            'guests_interval_minutes' => 1441,
        ])
        ->assertSessionHasErrors(['partners_interval_minutes', 'guests_interval_minutes']);
});

test('non-admin cannot access sync settings', function () {
    $operator = User::factory()->create([
        'role' => UserRole::Operator,
        'approved_at' => now(),
    ]);

    $this->actingAs($operator)
        ->get('/admin/sync')
        ->assertForbidden();
});
