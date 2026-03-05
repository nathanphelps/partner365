<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;

test('updating profile logs ProfileUpdated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'New Name',
        'email' => $user->email,
    ]);

    $log = ActivityLog::where('action', ActivityAction::ProfileUpdated)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
});

test('deleting account logs AccountDeleted before deletion', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete(route('profile.destroy'), [
        'password' => 'password',
    ]);

    $log = ActivityLog::where('action', ActivityAction::AccountDeleted)->first();
    expect($log)->not->toBeNull();
    expect($log->details['email'])->toBe($user->email);
});

test('changing password logs PasswordChanged', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $log = ActivityLog::where('action', ActivityAction::PasswordChanged)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
});
