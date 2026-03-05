<?php

use App\Models\User;

test('approved user can access protected routes', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

test('unapproved user is shown pending approval page', function () {
    $user = User::factory()->create(['approved_at' => null]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->component('PendingApproval'));
});

test('unapproved user cannot access settings profile', function () {
    $user = User::factory()->create(['approved_at' => null]);

    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertStatus(403);
});
