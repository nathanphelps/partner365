<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['auth', 'role:admin'])->get('/test-admin-route', fn () => 'ok');
    Route::middleware(['auth', 'role:admin,operator'])->get('/test-manage-route', fn () => 'ok');
});

test('admin can access admin routes', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($user)
        ->get('/test-admin-route')
        ->assertOk();
});

test('viewer cannot access admin routes', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/test-admin-route')
        ->assertForbidden();
});

test('operator can access manage routes', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);

    $this->actingAs($user)
        ->get('/test-manage-route')
        ->assertOk();
});

test('viewer cannot access manage routes', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/test-manage-route')
        ->assertForbidden();
});
