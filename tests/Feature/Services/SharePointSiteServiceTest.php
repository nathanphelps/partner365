<?php

use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\SharePointSite;
use App\Models\SharePointSitePermission;
use App\Services\SharePointSiteService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'graph.cloud_environment' => 'commercial',
        'graph.tenant_id' => 'test-tenant-id',
        'graph.client_id' => 'test-client-id',
        'graph.client_secret' => 'test-client-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
        'graph.sharepoint_tenant' => 'contoso',
    ]);

    Cache::forget('msgraph_access_token');
    Cache::forget('spo_admin_access_token');
});

function fakeGraphForSharePoint(array $sites = [], array $permissionsBySiteId = [], array $labelsBySiteId = [], array $spoSiteProperties = []): void
{
    $responses = [
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/sites?*' => Http::response([
            'value' => $sites,
        ]),
        '*-admin.sharepoint.com/_api/*' => Http::response([
            '_Child_Items_' => $spoSiteProperties,
            '_nextStartIndex' => -1,
        ]),
    ];

    foreach ($sites as $site) {
        $siteId = $site['id'];
        $permissions = $permissionsBySiteId[$siteId] ?? [];
        $responses["graph.microsoft.com/v1.0/sites/{$siteId}/permissions*"] = Http::response([
            'value' => $permissions,
        ]);

        $labelId = $labelsBySiteId[$siteId] ?? null;
        $responses["graph.microsoft.com/v1.0/sites/{$siteId}/sensitivityLabel*"] = Http::response(
            $labelId ? ['sensitivityLabelId' => $labelId] : []
        );
    }

    Http::fake($responses);
}

function makeGraphSite(array $overrides = []): array
{
    return array_merge([
        'id' => 'site-1',
        'displayName' => 'Project Alpha',
        'name' => 'ProjectAlpha',
        'webUrl' => 'https://contoso.sharepoint.com/sites/alpha',
        'description' => 'Collaboration site',
    ], $overrides);
}

function makeSpoSiteProperty(string $url, int $sharingCapability = 2): array
{
    return [
        'Url' => $url,
        'SharingCapability' => $sharingCapability,
    ];
}

test('syncSites upserts sites from Graph API', function () {
    fakeGraphForSharePoint(
        [makeGraphSite()],
        [],
        [],
        [makeSpoSiteProperty('https://contoso.sharepoint.com/sites/alpha', 2)]
    );

    $service = app(SharePointSiteService::class);
    $result = $service->syncSites();

    expect($result)->toBe(1);
    expect(SharePointSite::count())->toBe(1);

    $site = SharePointSite::first();
    expect($site->site_id)->toBe('site-1');
    expect($site->display_name)->toBe('Project Alpha');
    expect($site->url)->toBe('https://contoso.sharepoint.com/sites/alpha');
    expect($site->external_sharing_capability)->toBe('ExternalUserAndGuestSharing');
});

test('syncSites removes stale sites on re-sync', function () {
    SharePointSite::create([
        'site_id' => 'stale-site',
        'display_name' => 'Old Site',
        'url' => 'https://contoso.sharepoint.com/sites/old',
        'synced_at' => now()->subDay(),
    ]);

    fakeGraphForSharePoint([makeGraphSite()]);

    $service = app(SharePointSiteService::class);
    $service->syncSites();

    expect(SharePointSite::count())->toBe(1);
    expect(SharePointSite::first()->site_id)->toBe('site-1');
});

test('syncSites links sensitivity label when present', function () {
    $label = SensitivityLabel::create([
        'label_id' => 'label-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);

    fakeGraphForSharePoint(
        [makeGraphSite()],
        [],
        ['site-1' => 'label-1']
    );

    $service = app(SharePointSiteService::class);
    $service->syncSites();

    $site = SharePointSite::first();
    expect($site->sensitivity_label_id)->toBe($label->id);
});

test('syncPermissions matches guest users by email', function () {
    $partner = PartnerOrganization::factory()->create();
    $guest = GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'email' => 'guest@partner.com',
    ]);

    $site = SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    fakeGraphForSharePoint(
        [makeGraphSite()],
        [
            'site-1' => [
                [
                    'id' => 'perm-1',
                    'roles' => ['write'],
                    'grantedToV2' => [
                        'user' => ['email' => 'guest@partner.com'],
                    ],
                ],
            ],
        ]
    );

    $service = app(SharePointSiteService::class);
    $result = $service->syncPermissions();

    expect($result)->toBe(1);
    expect(SharePointSitePermission::count())->toBe(1);

    $perm = SharePointSitePermission::first();
    expect($perm->sharepoint_site_id)->toBe($site->id);
    expect($perm->guest_user_id)->toBe($guest->id);
    expect($perm->role)->toBe('write');
    expect($perm->granted_via)->toBe('direct');
});

test('syncPermissions skips non-guest users', function () {
    SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    fakeGraphForSharePoint(
        [makeGraphSite()],
        [
            'site-1' => [
                [
                    'id' => 'perm-1',
                    'roles' => ['read'],
                    'grantedToV2' => [
                        'user' => ['email' => 'unknown@external.com'],
                    ],
                ],
            ],
        ]
    );

    $service = app(SharePointSiteService::class);
    $result = $service->syncPermissions();

    expect($result)->toBe(0);
    expect(SharePointSitePermission::count())->toBe(0);
});

test('syncPermissions removes stale permissions', function () {
    $partner = PartnerOrganization::factory()->create();
    $guest = GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'email' => 'guest@partner.com',
    ]);

    $site = SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    SharePointSitePermission::create([
        'sharepoint_site_id' => $site->id,
        'guest_user_id' => $guest->id,
        'role' => 'owner',
        'granted_via' => 'direct',
    ]);

    fakeGraphForSharePoint(
        [makeGraphSite()],
        ['site-1' => []]
    );

    $service = app(SharePointSiteService::class);
    $service->syncPermissions();

    expect(SharePointSitePermission::count())->toBe(0);
});

test('syncPermissions detects sharing_link granted_via', function () {
    $partner = PartnerOrganization::factory()->create();
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'email' => 'guest@partner.com',
    ]);

    SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    fakeGraphForSharePoint(
        [makeGraphSite()],
        [
            'site-1' => [
                [
                    'id' => 'perm-1',
                    'roles' => ['read'],
                    'grantedToV2' => [
                        'user' => ['email' => 'guest@partner.com'],
                    ],
                    'link' => ['type' => 'view', 'webUrl' => 'https://...'],
                ],
            ],
        ]
    );

    $service = app(SharePointSiteService::class);
    $service->syncPermissions();

    expect(SharePointSitePermission::first()->granted_via)->toBe('sharing_link');
});

test('getPartnerExposure returns sites accessible by partner guests', function () {
    $partner = PartnerOrganization::factory()->create();
    $otherPartner = PartnerOrganization::factory()->create();

    $guest = GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'email' => 'guest@partner.com',
    ]);

    $site = SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    SharePointSite::create([
        'site_id' => 'site-2',
        'display_name' => 'Internal Only',
        'url' => 'https://contoso.sharepoint.com/sites/internal',
        'external_sharing_capability' => 'Disabled',
        'synced_at' => now(),
    ]);

    SharePointSitePermission::create([
        'sharepoint_site_id' => $site->id,
        'guest_user_id' => $guest->id,
        'role' => 'read',
        'granted_via' => 'direct',
    ]);

    $service = app(SharePointSiteService::class);
    $result = $service->getPartnerExposure($partner);

    expect($result)->toHaveCount(1);
    expect($result->first()->site_id)->toBe('site-1');

    $otherResult = $service->getPartnerExposure($otherPartner);
    expect($otherResult)->toHaveCount(0);
});

test('syncSites defaults to Disabled when SharePoint Admin API is unconfigured', function () {
    config(['graph.sharepoint_tenant' => null]);

    fakeGraphForSharePoint([makeGraphSite()]);

    $service = app(SharePointSiteService::class);
    $service->syncSites();

    $site = SharePointSite::first();
    expect($site->external_sharing_capability)->toBe('Disabled');
});
