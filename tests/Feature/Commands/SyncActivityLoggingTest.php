<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Http;

test('sync:partners creates SyncCompleted activity log on success', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test']),
        'graph.microsoft.com/*' => Http::response(['value' => []]),
    ]);

    $this->artisan('sync:partners')->assertSuccessful();

    $log = ActivityLog::where('action', ActivityAction::SyncCompleted)
        ->whereJsonContains('details->type', 'partners')
        ->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBeNull();
});

test('sync:guests creates SyncCompleted activity log on success', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test']),
        'graph.microsoft.com/*' => Http::response(['value' => []]),
    ]);

    $this->artisan('sync:guests')->assertSuccessful();

    $log = ActivityLog::where('action', ActivityAction::SyncCompleted)
        ->whereJsonContains('details->type', 'guests')
        ->first();
    expect($log)->not->toBeNull();
});

test('sync:conditional-access-policies creates ConditionalAccessPoliciesSynced activity log', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test']),
        'graph.microsoft.com/*' => Http::response(['value' => []]),
    ]);

    $this->artisan('sync:conditional-access-policies')->assertSuccessful();

    $log = ActivityLog::where('action', ActivityAction::ConditionalAccessPoliciesSynced)->first();
    expect($log)->not->toBeNull();
});
