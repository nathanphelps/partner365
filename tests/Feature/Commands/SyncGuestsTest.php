<?php

use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant',
        'graph.client_id' => 'test-client',
        'graph.client_secret' => 'test-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
    ]);
    Cache::forget('msgraph_access_token');
});

test('sync:guests creates guest records from graph api', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users*' => Http::response([
            'value' => [
                [
                    'id' => 'entra-guest-1',
                    'displayName' => 'Guest One',
                    'mail' => 'guest1@external.com',
                    'userPrincipalName' => 'guest1_external.com#EXT#@contoso.com',
                    'signInActivity' => ['lastSignInDateTime' => '2026-02-01T10:00:00Z'],
                ],
            ],
        ]),
    ]);

    $this->artisan('sync:guests')
        ->expectsOutput('Fetching guest users from Graph API...')
        ->expectsOutput('Synced 1 guest users.')
        ->assertSuccessful();

    expect(GuestUser::count())->toBe(1);
    expect(GuestUser::first()->email)->toBe('guest1@external.com');
    expect(GuestUser::first()->display_name)->toBe('Guest One');
});

test('sync:guests matches guests to partner organizations by domain', function () {
    $partner = PartnerOrganization::factory()->create(['domain' => 'external.com']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users*' => Http::response([
            'value' => [
                [
                    'id' => 'entra-guest-2',
                    'displayName' => 'Guest Two',
                    'mail' => 'guest2@external.com',
                    'userPrincipalName' => 'guest2_external.com#EXT#@contoso.com',
                ],
            ],
        ]),
    ]);

    $this->artisan('sync:guests')->assertSuccessful();

    expect(GuestUser::first()->partner_organization_id)->toBe($partner->id);
});

test('sync:guests creates a SyncLog entry on success', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users*' => Http::response(['value' => []]),
    ]);

    $this->artisan('sync:guests')->assertSuccessful();

    $log = SyncLog::where('type', 'guests')->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe('completed');
    expect($log->records_synced)->toBe(0);
});
