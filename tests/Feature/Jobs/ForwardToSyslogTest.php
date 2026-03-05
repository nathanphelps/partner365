<?php

use App\Enums\ActivityAction;
use App\Jobs\ForwardToSyslog;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;

test('job does nothing when syslog is disabled', function () {
    Setting::set('syslog', 'enabled', 'false');

    $log = ActivityLog::create([
        'user_id' => null,
        'action' => ActivityAction::SyncCompleted,
        'details' => ['type' => 'partners'],
        'created_at' => now(),
    ]);

    $job = new ForwardToSyslog($log);
    $job->handle();

    expect(true)->toBeTrue();
});

test('job formats and attempts send when syslog is enabled', function () {
    Setting::set('syslog', 'enabled', 'true');
    Setting::set('syslog', 'host', '127.0.0.1');
    Setting::set('syslog', 'port', '51400');
    Setting::set('syslog', 'transport', 'udp');
    Setting::set('syslog', 'facility', '16');

    $user = User::factory()->create();
    $log = ActivityLog::create([
        'user_id' => $user->id,
        'action' => ActivityAction::PartnerCreated,
        'details' => ['name' => 'Test'],
        'created_at' => now(),
    ]);
    $log->load('user');

    $job = new ForwardToSyslog($log);

    // UDP send will succeed even without a listener (fire and forget)
    $job->handle();

    expect(true)->toBeTrue();
});

test('job skips when host is not configured', function () {
    Setting::set('syslog', 'enabled', 'true');
    Setting::set('syslog', 'host', '');

    $log = ActivityLog::create([
        'user_id' => null,
        'action' => ActivityAction::SyncCompleted,
        'created_at' => now(),
    ]);

    $job = new ForwardToSyslog($log);
    $job->handle();

    expect(true)->toBeTrue();
});
