<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant-id',
        'graph.client_id' => 'test-client-id',
        'graph.client_secret' => 'test-client-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
    ]);
});

test('sync:sharepoint-sites command creates sync log and activity log on success', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response(['value' => []]),
    ]);

    $this->artisan('sync:sharepoint-sites')->assertSuccessful();

    $syncLog = SyncLog::where('type', 'sharepoint_sites')->first();
    expect($syncLog)->not->toBeNull();
    expect($syncLog->status)->toBe('completed');

    $activityLog = ActivityLog::where('action', ActivityAction::SharePointSitesSynced)->first();
    expect($activityLog)->not->toBeNull();
    expect($activityLog->user_id)->toBeNull();
});

test('sync:sharepoint-sites command logs failure on error', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/sites*' => Http::response([], 500),
    ]);

    $this->artisan('sync:sharepoint-sites')->assertFailed();

    $syncLog = SyncLog::where('type', 'sharepoint_sites')->first();
    expect($syncLog)->not->toBeNull();
    expect($syncLog->status)->toBe('failed');
    expect($syncLog->error_message)->not->toBeNull();
});
