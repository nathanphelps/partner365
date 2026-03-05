<?php

use App\Models\User;

test('new user is not approved by default', function () {
    $user = User::factory()->create(['approved_at' => null]);

    expect($user->isApproved())->toBeFalse();
    expect($user->approved_at)->toBeNull();
});

test('user can be approved', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['approved_at' => null]);

    $user->approve($admin);

    expect($user->isApproved())->toBeTrue();
    expect($user->approved_at)->not->toBeNull();
    expect($user->approved_by)->toBe($admin->id);
});

test('scopePending returns only unapproved users', function () {
    User::factory()->create();
    User::factory()->create(['approved_at' => null]);
    User::factory()->create(['approved_at' => null]);

    expect(User::pending()->count())->toBe(2);
});
