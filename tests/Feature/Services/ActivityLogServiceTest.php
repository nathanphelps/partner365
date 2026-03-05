<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\ActivityLogService;

test('it logs an activity', function () {
    $user = User::factory()->create();
    $partner = PartnerOrganization::factory()->create();

    $service = app(ActivityLogService::class);
    $service->log(
        user: $user,
        action: ActivityAction::PartnerCreated,
        subject: $partner,
        details: ['tenant_id' => $partner->tenant_id],
    );

    $log = ActivityLog::first();
    expect($log->action)->toBe(ActivityAction::PartnerCreated);
    expect($log->user_id)->toBe($user->id);
    expect($log->subject_type)->toBe(PartnerOrganization::class);
    expect($log->subject_id)->toBe($partner->id);
    expect($log->details['tenant_id'])->toBe($partner->tenant_id);
});

test('it logs a system activity without a user', function () {
    $service = app(ActivityLogService::class);
    $log = $service->logSystem(ActivityAction::SyncCompleted, details: ['type' => 'partners', 'records_synced' => 5]);

    expect($log->user_id)->toBeNull();
    expect($log->action)->toBe(ActivityAction::SyncCompleted);
    expect($log->details['records_synced'])->toBe(5);
});

test('it retrieves recent activity', function () {
    $user = User::factory()->create();
    $service = app(ActivityLogService::class);

    for ($i = 0; $i < 25; $i++) {
        $service->log($user, ActivityAction::SyncCompleted, details: ['count' => $i]);
    }

    $recent = $service->recent(20);
    expect($recent)->toHaveCount(20);
});
