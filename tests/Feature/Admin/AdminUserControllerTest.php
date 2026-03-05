<?php

use App\Enums\UserRole;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'approved_at' => now(),
    ]);
});

test('admin can view users list', function () {
    User::factory()->count(3)->create(['approved_at' => now()]);
    User::factory()->count(2)->create(['approved_at' => null]);

    $this->actingAs($this->admin)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Users')
            ->has('users.data', 6)
        );
});

test('pending users are listed first', function () {
    $approved = User::factory()->create(['name' => 'Approved User', 'approved_at' => now()]);
    $pending = User::factory()->create(['name' => 'Pending User', 'approved_at' => null]);

    $this->actingAs($this->admin)
        ->get('/admin/users')
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.name', 'Pending User')
        );
});

test('admin can approve a user', function () {
    $user = User::factory()->create(['approved_at' => null]);

    $this->actingAs($this->admin)
        ->post("/admin/users/{$user->id}/approve")
        ->assertRedirect();

    $user->refresh();
    expect($user->isApproved())->toBeTrue();
    expect($user->approved_by)->toBe($this->admin->id);
});

test('admin can change user role', function () {
    $user = User::factory()->create([
        'role' => UserRole::Viewer,
        'approved_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->patch("/admin/users/{$user->id}/role", ['role' => 'operator'])
        ->assertRedirect();

    expect($user->refresh()->role)->toBe(UserRole::Operator);
});

test('admin cannot change own role', function () {
    $this->actingAs($this->admin)
        ->patch("/admin/users/{$this->admin->id}/role", ['role' => 'viewer'])
        ->assertForbidden();
});

test('admin can delete a user', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($this->admin)
        ->delete("/admin/users/{$user->id}")
        ->assertRedirect();

    expect(User::find($user->id))->toBeNull();
});

test('admin cannot delete self', function () {
    $this->actingAs($this->admin)
        ->delete("/admin/users/{$this->admin->id}")
        ->assertForbidden();
});

test('non-admin cannot access user management', function () {
    $operator = User::factory()->create([
        'role' => UserRole::Operator,
        'approved_at' => now(),
    ]);

    $this->actingAs($operator)
        ->get('/admin/users')
        ->assertForbidden();
});

test('role validation rejects invalid role', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($this->admin)
        ->patch("/admin/users/{$user->id}/role", ['role' => 'superadmin'])
        ->assertSessionHasErrors('role');
});
