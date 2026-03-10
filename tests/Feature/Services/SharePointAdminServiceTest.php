<?php

use App\Services\SharePointAdminService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant-id',
        'graph.client_id' => 'test-client-id',
        'graph.client_secret' => 'test-client-secret',
        'graph.cloud_environment' => 'commercial',
        'graph.sharepoint_tenant' => 'contoso',
    ]);

    Cache::forget('spo_admin_access_token');
});

function makeSpoSitePropertyFull(array $overrides = []): array
{
    return array_merge([
        'Url' => 'https://contoso.sharepoint.com/sites/alpha',
        'SharingCapability' => 2,
        'SharingDomainRestrictionMode' => 1,
        'SharingAllowedDomainList' => 'fabrikam.com contoso.com',
        'SharingBlockedDomainList' => '',
        'DefaultSharingLinkType' => 1,
        'DefaultLinkPermission' => 1,
        'ExternalUserExpirationInDays' => 90,
        'OverrideTenantExternalUserExpirationPolicy' => true,
        'ConditionalAccessPolicy' => 1,
        'AllowEditing' => false,
        'LimitedAccessFileType' => 1,
        'AllowDownloadingNonWebViewableFiles' => false,
    ], $overrides);
}

function fakeSpoAdmin(array $siteProperties = []): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-spo-token',
            'expires_in' => 3600,
        ]),
        'contoso-admin.sharepoint.com/*' => Http::response([
            '_Child_Items_' => $siteProperties,
            '_nextStartIndex' => -1,
        ]),
    ]);
}

test('getSiteProperties returns enriched site data', function () {
    fakeSpoAdmin([makeSpoSitePropertyFull()]);

    $service = app(SharePointAdminService::class);
    $result = $service->getSiteProperties();

    $key = 'https://contoso.sharepoint.com/sites/alpha';
    expect($result)->toHaveKey($key);

    $site = $result[$key];
    expect($site['sharingCapability'])->toBe('ExternalUserAndGuestSharing');
    expect($site['sharingDomainRestrictionMode'])->toBe('AllowList');
    expect($site['sharingAllowedDomainList'])->toBe('fabrikam.com contoso.com');
    expect($site['sharingBlockedDomainList'])->toBe('');
    expect($site['defaultSharingLinkType'])->toBe('Direct');
    expect($site['defaultLinkPermission'])->toBe('View');
    expect($site['externalUserExpirationInDays'])->toBe(90);
    expect($site['overrideTenantExternalUserExpirationPolicy'])->toBeTrue();
    expect($site['conditionalAccessPolicy'])->toBe('AllowLimitedAccess');
    expect($site['allowEditing'])->toBeFalse();
    expect($site['limitedAccessFileType'])->toBe('WebPreviewableFiles');
    expect($site['allowDownloadingNonWebViewableFiles'])->toBeFalse();
});

test('getSiteProperties maps enum values correctly', function () {
    fakeSpoAdmin([
        makeSpoSitePropertyFull([
            'SharingCapability' => 0,
            'ConditionalAccessPolicy' => 2,
            'LimitedAccessFileType' => 0,
            'SharingDomainRestrictionMode' => 2,
            'DefaultSharingLinkType' => 3,
            'DefaultLinkPermission' => 2,
        ]),
    ]);

    $service = app(SharePointAdminService::class);
    $result = $service->getSiteProperties();

    $site = $result['https://contoso.sharepoint.com/sites/alpha'];
    expect($site['sharingCapability'])->toBe('Disabled');
    expect($site['conditionalAccessPolicy'])->toBe('BlockAccess');
    expect($site['limitedAccessFileType'])->toBe('OfficeOnlineFilesOnly');
    expect($site['sharingDomainRestrictionMode'])->toBe('BlockList');
    expect($site['defaultSharingLinkType'])->toBe('AnonymousAccess');
    expect($site['defaultLinkPermission'])->toBe('Edit');
});

test('getSiteProperties handles pagination', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-spo-token',
            'expires_in' => 3600,
        ]),
        'contoso-admin.sharepoint.com/*' => Http::sequence()
            ->push([
                '_Child_Items_' => [makeSpoSitePropertyFull()],
                '_nextStartIndex' => 1,
            ])
            ->push([
                '_Child_Items_' => [makeSpoSitePropertyFull([
                    'Url' => 'https://contoso.sharepoint.com/sites/beta',
                ])],
                '_nextStartIndex' => -1,
            ]),
    ]);

    $service = app(SharePointAdminService::class);
    $result = $service->getSiteProperties();

    expect($result)->toHaveCount(2);
    expect($result)->toHaveKey('https://contoso.sharepoint.com/sites/alpha');
    expect($result)->toHaveKey('https://contoso.sharepoint.com/sites/beta');
});
