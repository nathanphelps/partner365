<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;

test('successful login logs UserLoggedIn', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $log = ActivityLog::where('action', ActivityAction::UserLoggedIn)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
});

test('failed login logs LoginFailed', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $log = ActivityLog::where('action', ActivityAction::LoginFailed)->first();
    expect($log)->not->toBeNull();
    expect($log->details['email'])->toBe($user->email);
});

test('logout logs UserLoggedOut', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout');

    $log = ActivityLog::where('action', ActivityAction::UserLoggedOut)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
});
