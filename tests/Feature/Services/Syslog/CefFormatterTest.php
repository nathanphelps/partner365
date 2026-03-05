<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Syslog\CefFormatter;

test('it formats an activity log as CEF', function () {
    $user = User::factory()->create(['name' => 'John Doe']);
    $log = ActivityLog::create([
        'user_id' => $user->id,
        'action' => ActivityAction::PartnerCreated,
        'details' => ['tenant_id' => '123'],
        'created_at' => now(),
    ]);
    $log->load('user');

    $formatter = new CefFormatter;
    $cef = $formatter->format($log);

    expect($cef)->toStartWith('CEF:0|Partner365|Partner365|1.0|partner_created|');
    expect($cef)->toContain('suser=John Doe');
    expect($cef)->toContain('|5|');
});

test('it assigns correct severity levels', function () {
    $formatter = new CefFormatter;

    expect($formatter->severity(ActivityAction::SyncCompleted))->toBe(3);
    expect($formatter->severity(ActivityAction::PartnerCreated))->toBe(5);
    expect($formatter->severity(ActivityAction::PartnerDeleted))->toBe(7);
    expect($formatter->severity(ActivityAction::LoginFailed))->toBe(8);
});

test('it escapes pipes and backslashes in CEF fields', function () {
    $user = User::factory()->create(['name' => 'John|Doe\\Jr']);
    $log = ActivityLog::create([
        'user_id' => $user->id,
        'action' => ActivityAction::ProfileUpdated,
        'details' => ['note' => 'test|pipe'],
        'created_at' => now(),
    ]);
    $log->load('user');

    $formatter = new CefFormatter;
    $cef = $formatter->format($log);

    expect($cef)->toContain('suser=John\\|Doe\\\\Jr');
});

test('it handles system events with no user', function () {
    $log = ActivityLog::create([
        'user_id' => null,
        'action' => ActivityAction::SyncCompleted,
        'details' => ['type' => 'partners'],
        'created_at' => now(),
    ]);

    $formatter = new CefFormatter;
    $cef = $formatter->format($log);

    expect($cef)->toContain('suser=System');
});
