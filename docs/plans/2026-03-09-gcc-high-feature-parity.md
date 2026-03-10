# GCC High Feature Parity Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Achieve full feature parity between Commercial and GCC High tenants for SharePoint sharing controls, sensitivity labels, external user mapping, and read-only access restrictions.

**Architecture:** Three-tier label sync (Graph API → PowerShell → stub fallback), enriched SPO Admin API data for sharing/access controls, User Information List for per-site guest mapping. All tiers degrade gracefully — GCC High never crashes, just uses alternative data sources.

**Tech Stack:** Laravel 12, PHP 8.2+, Vue 3/TypeScript/Inertia.js, PowerShell 7 (ExchangeOnlineManagement module), SharePoint Admin REST API, Microsoft Graph API.

**Spec:** `docs/superpowers/specs/2026-03-09-gcc-high-feature-parity-design.md`

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `database/migrations/2026_03_10_000000_add_access_controls_to_sharepoint_sites.php` | Create | New columns for sharing/access data |
| `config/graph.php` | Modify | Add compliance_certificate_path, compliance_certificate_password, sync_site_users |
| `.env.example` | Modify | Add 3 new env vars |
| `app/Services/SharePointAdminService.php` | Modify | Enrich getSiteProperties() return data |
| `app/Services/CompliancePowerShellService.php` | Create | PowerShell Get-Label/Get-LabelPolicy wrapper |
| `app/Services/SensitivityLabelService.php` | Modify | Three-tier label sync, Groups API, SPO REST, stub creation |
| `app/Services/SharePointSiteService.php` | Modify | Consume enriched data, new syncSiteExternalUsers() |
| `app/Models/SharePointSite.php` | Modify | Add new fillable fields and casts |
| `app/Console/Commands/SyncSensitivityLabels.php` | Modify | Tier reporting in output |
| `app/Console/Commands/SyncSharePointSites.php` | Modify | Add external user sync step |
| `app/Http/Controllers/Admin/AdminGraphController.php` | Modify | Certificate settings read/write |
| `app/Http/Requests/UpdateGraphSettingsRequest.php` | Modify | Certificate validation rules |
| `resources/js/pages/admin/Graph.vue` | Modify | Certificate fields (GCC High conditional) |
| `resources/js/types/sharepoint.ts` | Modify | Add new field types |
| `resources/js/pages/sharepoint-sites/Index.vue` | Modify | Access control columns |
| `resources/js/pages/sharepoint-sites/Show.vue` | Modify | Access control fields |
| `resources/js/pages/sensitivity-labels/Index.vue` | Modify | Stub banner, unknown protection badge |
| `resources/js/types/sensitivity-label.ts` | Modify | Add 'unknown' to protection_type |
| `Dockerfile` | Modify | Add pwsh + ExchangeOnlineManagement |
| `tests/Feature/Services/SharePointAdminServiceTest.php` | Create | Test enriched data parsing |
| `tests/Feature/Services/CompliancePowerShellServiceTest.php` | Create | Test PowerShell output parsing |
| `tests/Feature/Services/SharePointSiteServiceTest.php` | Modify | Update mocks, add User Info List tests |
| `tests/Feature/Services/SensitivityLabelServiceTest.php` | Modify | Three-tier tests, stub creation tests |

---

## Chunk 1: Database, Config & Model

### Task 1: Migration — add access control columns to sharepoint_sites

**Files:**
- Create: `database/migrations/2026_03_10_000000_add_access_controls_to_sharepoint_sites.php`

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration add_access_controls_to_sharepoint_sites --table=sharepoint_sites --no-interaction
```

- [ ] **Step 2: Write the migration**

Replace the generated migration content with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sharepoint_sites', function (Blueprint $table) {
            // Sharing controls
            $table->string('sharing_domain_restriction_mode')->nullable()->after('external_sharing_capability');
            $table->text('sharing_allowed_domain_list')->nullable()->after('sharing_domain_restriction_mode');
            $table->text('sharing_blocked_domain_list')->nullable()->after('sharing_allowed_domain_list');
            $table->string('default_sharing_link_type')->nullable()->after('sharing_blocked_domain_list');
            $table->string('default_link_permission')->nullable()->after('default_sharing_link_type');

            // External user expiration
            $table->integer('external_user_expiration_days')->nullable()->after('default_link_permission');
            $table->boolean('override_tenant_expiration_policy')->default(false)->after('external_user_expiration_days');

            // Access restrictions (read-only caps)
            $table->string('conditional_access_policy')->nullable()->after('override_tenant_expiration_policy');
            $table->boolean('allow_editing')->default(true)->after('conditional_access_policy');
            $table->string('limited_access_file_type')->nullable()->after('allow_editing');
            $table->boolean('allow_downloading_non_web_viewable')->default(true)->after('limited_access_file_type');
        });
    }

    public function down(): void
    {
        Schema::table('sharepoint_sites', function (Blueprint $table) {
            $table->dropColumn([
                'sharing_domain_restriction_mode',
                'sharing_allowed_domain_list',
                'sharing_blocked_domain_list',
                'default_sharing_link_type',
                'default_link_permission',
                'external_user_expiration_days',
                'override_tenant_expiration_policy',
                'conditional_access_policy',
                'allow_editing',
                'limited_access_file_type',
                'allow_downloading_non_web_viewable',
            ]);
        });
    }
};
```

- [ ] **Step 3: Run the migration**

```bash
php artisan migrate --no-interaction
```

Expected: Migration runs successfully.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*add_access_controls_to_sharepoint_sites*
git commit -m "feat: add access control columns to sharepoint_sites table"
```

### Task 2: Update SharePointSite model

**Files:**
- Modify: `app/Models/SharePointSite.php`

- [ ] **Step 1: Add new fields to fillable array**

In `app/Models/SharePointSite.php`, add to the `$fillable` array (currently lines 14-20):

```php
protected $fillable = [
    'site_id',
    'display_name',
    'url',
    'description',
    'sensitivity_label_id',
    'external_sharing_capability',
    'sharing_domain_restriction_mode',
    'sharing_allowed_domain_list',
    'sharing_blocked_domain_list',
    'default_sharing_link_type',
    'default_link_permission',
    'external_user_expiration_days',
    'override_tenant_expiration_policy',
    'conditional_access_policy',
    'allow_editing',
    'limited_access_file_type',
    'allow_downloading_non_web_viewable',
    'storage_used_bytes',
    'last_activity_at',
    'owner_display_name',
    'owner_email',
    'member_count',
    'raw_json',
    'synced_at',
];
```

- [ ] **Step 2: Add casts for new boolean/integer fields**

Add to the `casts()` method (currently lines 22-31):

```php
protected function casts(): array
{
    return [
        'storage_used_bytes' => 'integer',
        'member_count' => 'integer',
        'external_user_expiration_days' => 'integer',
        'override_tenant_expiration_policy' => 'boolean',
        'allow_editing' => 'boolean',
        'allow_downloading_non_web_viewable' => 'boolean',
        'raw_json' => 'array',
        'last_activity_at' => 'datetime',
        'synced_at' => 'datetime',
    ];
}
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/SharePointSite.php
git commit -m "feat: add access control fields to SharePointSite model"
```

### Task 3: Update config and .env.example

**Files:**
- Modify: `config/graph.php`
- Modify: `.env.example`

- [ ] **Step 1: Add config entries**

In `config/graph.php`, add after the `'sharepoint_tenant'` line:

```php
'compliance_certificate_path' => env('COMPLIANCE_CERTIFICATE_PATH'),
'compliance_certificate_password' => env('COMPLIANCE_CERTIFICATE_PASSWORD'),
'sync_site_users' => env('MICROSOFT_GRAPH_SYNC_SITE_USERS', true),
```

- [ ] **Step 2: Add env vars to .env.example**

In `.env.example`, add after the `MICROSOFT_GRAPH_SHAREPOINT_TENANT=` line:

```env
COMPLIANCE_CERTIFICATE_PATH=
COMPLIANCE_CERTIFICATE_PASSWORD=
MICROSOFT_GRAPH_SYNC_SITE_USERS=true
```

- [ ] **Step 3: Commit**

```bash
git add config/graph.php .env.example
git commit -m "feat: add compliance certificate and sync_site_users config"
```

---

## Chunk 2: SharePointAdminService Enrichment

### Task 4: Enrich getSiteProperties() return data

**Files:**
- Modify: `app/Services/SharePointAdminService.php`
- Create: `tests/Feature/Services/SharePointAdminServiceTest.php`

- [ ] **Step 1: Write the test file**

```bash
php artisan make:test Services/SharePointAdminServiceTest --pest --no-interaction
```

Write the test file `tests/Feature/Services/SharePointAdminServiceTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=SharePointAdminServiceTest --compact
```

Expected: Tests fail because `getSiteProperties()` still returns flat strings.

- [ ] **Step 3: Add enum maps to SharePointAdminService**

In `app/Services/SharePointAdminService.php`, add after the existing `SHARING_MAP` constant (line 18):

```php
    private const CONDITIONAL_ACCESS_MAP = [
        0 => 'AllowFullAccess',
        1 => 'AllowLimitedAccess',
        2 => 'BlockAccess',
        3 => 'AuthenticationContext',
    ];

    private const LIMITED_ACCESS_FILE_TYPE_MAP = [
        0 => 'OfficeOnlineFilesOnly',
        1 => 'WebPreviewableFiles',
        2 => 'OtherFiles',
    ];

    private const SHARING_DOMAIN_RESTRICTION_MAP = [
        0 => 'None',
        1 => 'AllowList',
        2 => 'BlockList',
    ];

    private const SHARING_LINK_TYPE_MAP = [
        0 => 'None',
        1 => 'Direct',
        2 => 'Internal',
        3 => 'AnonymousAccess',
    ];

    private const SHARING_PERMISSION_MAP = [
        0 => 'None',
        1 => 'View',
        2 => 'Edit',
    ];
```

- [ ] **Step 4: Update getSiteProperties() to return enriched data**

Replace the `foreach ($items as $item)` block inside `getSiteProperties()` (currently lines 106-111) with:

```php
            foreach ($items as $item) {
                $url = rtrim($item['Url'] ?? '', '/');
                $normalizedUrl = strtolower($url);
                $results[$normalizedUrl] = [
                    'sharingCapability' => self::SHARING_MAP[$item['SharingCapability'] ?? 0] ?? 'Disabled',
                    'sharingDomainRestrictionMode' => self::SHARING_DOMAIN_RESTRICTION_MAP[$item['SharingDomainRestrictionMode'] ?? 0] ?? 'None',
                    'sharingAllowedDomainList' => $item['SharingAllowedDomainList'] ?? '',
                    'sharingBlockedDomainList' => $item['SharingBlockedDomainList'] ?? '',
                    'defaultSharingLinkType' => self::SHARING_LINK_TYPE_MAP[$item['DefaultSharingLinkType'] ?? 0] ?? 'None',
                    'defaultLinkPermission' => self::SHARING_PERMISSION_MAP[$item['DefaultLinkPermission'] ?? 0] ?? 'None',
                    'externalUserExpirationInDays' => $item['ExternalUserExpirationInDays'] ?? null,
                    'overrideTenantExternalUserExpirationPolicy' => (bool) ($item['OverrideTenantExternalUserExpirationPolicy'] ?? false),
                    'conditionalAccessPolicy' => self::CONDITIONAL_ACCESS_MAP[$item['ConditionalAccessPolicy'] ?? 0] ?? 'AllowFullAccess',
                    'allowEditing' => (bool) ($item['AllowEditing'] ?? true),
                    'limitedAccessFileType' => self::LIMITED_ACCESS_FILE_TYPE_MAP[$item['LimitedAccessFileType'] ?? 1] ?? 'WebPreviewableFiles',
                    'allowDownloadingNonWebViewableFiles' => (bool) ($item['AllowDownloadingNonWebViewableFiles'] ?? true),
                ];
            }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --filter=SharePointAdminServiceTest --compact
```

Expected: All 3 tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Services/SharePointAdminService.php tests/Feature/Services/SharePointAdminServiceTest.php
git commit -m "feat: enrich SharePointAdminService with sharing and access control data"
```

---

## Chunk 3: Update Consumers of getSiteProperties()

### Task 5: Update SharePointSiteService to use enriched data

**Files:**
- Modify: `app/Services/SharePointSiteService.php`
- Modify: `tests/Feature/Services/SharePointSiteServiceTest.php`

- [ ] **Step 1: Update test helpers for new data shape**

In `tests/Feature/Services/SharePointSiteServiceTest.php`, replace the `makeSpoSiteProperty()` helper (currently lines 70-76) with:

```php
function makeSpoSiteProperty(array $overrides = []): array
{
    return array_merge([
        'sharingCapability' => 'ExternalUserAndGuestSharing',
        'sharingDomainRestrictionMode' => 'None',
        'sharingAllowedDomainList' => '',
        'sharingBlockedDomainList' => '',
        'defaultSharingLinkType' => 'None',
        'defaultLinkPermission' => 'None',
        'externalUserExpirationInDays' => null,
        'overrideTenantExternalUserExpirationPolicy' => false,
        'conditionalAccessPolicy' => 'AllowFullAccess',
        'allowEditing' => true,
        'limitedAccessFileType' => 'WebPreviewableFiles',
        'allowDownloadingNonWebViewableFiles' => true,
    ], $overrides);
}
```

- [ ] **Step 2: Run existing tests to see failures**

```bash
php artisan test --filter=SharePointSiteServiceTest --compact
```

Expected: Tests fail because `syncSites()` still reads `$sharingMap[$siteUrl]` as a string.

- [ ] **Step 3: Update syncSites() to consume enriched data**

In `app/Services/SharePointSiteService.php`, update the `syncSites()` method. Replace the sharing capability lookup (find the line that reads `$sharingMap[$siteUrl] ?? 'Disabled'`) with:

```php
            $siteUrl = strtolower(rtrim($graphSite['webUrl'] ?? '', '/'));
            $siteData = $sharingMap[$siteUrl] ?? [];
            $sharingCapability = $siteData['sharingCapability'] ?? 'Disabled';
```

And update the `updateOrCreate` call to store all enriched fields (keep existing fields like `storage_used_bytes`, `last_activity_at`, `member_count`):

```php
            $site = SharePointSite::updateOrCreate(
                ['site_id' => $graphSite['id']],
                [
                    'display_name' => $graphSite['displayName'] ?? $graphSite['name'] ?? 'Unknown',
                    'url' => $graphSite['webUrl'] ?? '',
                    'description' => $graphSite['description'] ?? null,
                    'sensitivity_label_id' => $sensitivityLabel?->id,
                    'external_sharing_capability' => $sharingCapability,
                    'sharing_domain_restriction_mode' => $siteData['sharingDomainRestrictionMode'] ?? null,
                    'sharing_allowed_domain_list' => $siteData['sharingAllowedDomainList'] ?? null,
                    'sharing_blocked_domain_list' => $siteData['sharingBlockedDomainList'] ?? null,
                    'default_sharing_link_type' => $siteData['defaultSharingLinkType'] ?? null,
                    'default_link_permission' => $siteData['defaultLinkPermission'] ?? null,
                    'external_user_expiration_days' => $siteData['externalUserExpirationInDays'] ?? null,
                    'override_tenant_expiration_policy' => $siteData['overrideTenantExternalUserExpirationPolicy'] ?? false,
                    'conditional_access_policy' => $siteData['conditionalAccessPolicy'] ?? null,
                    'allow_editing' => $siteData['allowEditing'] ?? true,
                    'limited_access_file_type' => $siteData['limitedAccessFileType'] ?? null,
                    'allow_downloading_non_web_viewable' => $siteData['allowDownloadingNonWebViewableFiles'] ?? true,
                    'storage_used_bytes' => $graphSite['storageUsed'] ?? null,
                    'last_activity_at' => $graphSite['lastModifiedDateTime'] ?? null,
                    'owner_display_name' => $graphSite['createdBy']['user']['displayName'] ?? null,
                    'owner_email' => $graphSite['createdBy']['user']['email'] ?? null,
                    'member_count' => null,
                    'raw_json' => $graphSite,
                    'synced_at' => now(),
                ]
            );
```

- [ ] **Step 4: Update fetchSharingCapabilities() return type**

No signature change needed — it already returns `array`. The internal shape changed from `string` values to `array` values, which is handled by the callers.

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter=SharePointSiteServiceTest --compact
```

Expected: All 9 existing tests pass.

- [ ] **Step 6: Add test for enriched data storage**

Add to `tests/Feature/Services/SharePointSiteServiceTest.php`:

```php
test('syncSites stores access control data from SPO Admin API', function () {
    fakeGraphForSharePoint(
        sites: [makeGraphSite()],
        spoSiteProperties: [makeSpoSiteProperty([
            'conditionalAccessPolicy' => 'AllowLimitedAccess',
            'allowEditing' => false,
            'sharingDomainRestrictionMode' => 'AllowList',
            'sharingAllowedDomainList' => 'fabrikam.com',
            'externalUserExpirationInDays' => 90,
            'overrideTenantExternalUserExpirationPolicy' => true,
        ])],
    );

    $service = app(SharePointSiteService::class);
    $service->syncSites();

    $site = SharePointSite::first();
    expect($site->conditional_access_policy)->toBe('AllowLimitedAccess');
    expect($site->allow_editing)->toBeFalse();
    expect($site->sharing_domain_restriction_mode)->toBe('AllowList');
    expect($site->sharing_allowed_domain_list)->toBe('fabrikam.com');
    expect($site->external_user_expiration_days)->toBe(90);
    expect($site->override_tenant_expiration_policy)->toBeTrue();
});
```

- [ ] **Step 7: Run all SharePoint tests**

```bash
php artisan test --filter=SharePointSiteServiceTest --compact
```

Expected: All 10 tests pass.

- [ ] **Step 8: Commit**

```bash
git add app/Services/SharePointSiteService.php tests/Feature/Services/SharePointSiteServiceTest.php
git commit -m "feat: store enriched sharing and access control data in SharePoint sync"
```

### Task 6: Update SensitivityLabelService to use enriched sharing data

**Files:**
- Modify: `app/Services/SensitivityLabelService.php`

- [ ] **Step 1: Update syncSiteLabels() sharing lookup**

In `app/Services/SensitivityLabelService.php`, in `syncSiteLabels()` (line 91), change:

```php
            $sharingCapability = $sharingMap[$siteUrl] ?? 'Disabled';
```

to:

```php
            $siteData = $sharingMap[$siteUrl] ?? [];
            $sharingCapability = $siteData['sharingCapability'] ?? 'Disabled';
```

- [ ] **Step 2: Run sensitivity label tests**

```bash
php artisan test --filter=SensitivityLabelServiceTest --compact
```

Expected: All 8 tests pass (the tests don't mock SPO admin data, so they use empty map fallback).

- [ ] **Step 3: Commit**

```bash
git add app/Services/SensitivityLabelService.php
git commit -m "fix: adapt SensitivityLabelService to enriched sharing data format"
```

- [ ] **Step 4: Run all tests to verify no regressions**

```bash
php artisan test --compact
```

Expected: All tests pass.

---

## Chunk 4: CompliancePowerShellService

### Task 7: Create CompliancePowerShellService

**Files:**
- Create: `app/Services/CompliancePowerShellService.php`
- Create: `tests/Feature/Services/CompliancePowerShellServiceTest.php`

- [ ] **Step 1: Write the test file**

```bash
php artisan make:test Services/CompliancePowerShellServiceTest --pest --no-interaction
```

Write `tests/Feature/Services/CompliancePowerShellServiceTest.php`:

```php
<?php

use App\Services\CompliancePowerShellService;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant-id',
        'graph.client_id' => 'test-client-id',
        'graph.cloud_environment' => 'commercial',
        'graph.compliance_certificate_path' => '/path/to/cert.pfx',
        'graph.compliance_certificate_password' => 'test-password',
    ]);
});

test('isAvailable returns false when certificate path is not configured', function () {
    config(['graph.compliance_certificate_path' => null]);

    $service = app(CompliancePowerShellService::class);

    expect($service->isAvailable())->toBeFalse();
});

test('isAvailable returns false when pwsh is not found', function () {
    Process::fake([
        'which pwsh*' => Process::result(output: '', exitCode: 1),
        'where pwsh*' => Process::result(output: '', exitCode: 1),
    ]);

    $service = app(CompliancePowerShellService::class);

    expect($service->isAvailable())->toBeFalse();
});

test('isAvailable returns true when pwsh exists and certificate configured', function () {
    Process::fake([
        '*pwsh*' => Process::result(output: '/usr/bin/pwsh', exitCode: 0),
    ]);

    $service = app(CompliancePowerShellService::class);

    expect($service->isAvailable())->toBeTrue();
});

test('parseLabelsOutput converts PowerShell JSON to label array', function () {
    $psOutput = json_encode([
        [
            'ImmutableId' => '12345678-1234-1234-1234-123456789012',
            'DisplayName' => 'Confidential',
            'Name' => 'Confidential',
            'Comment' => 'Apply to confidential data',
            'Tooltip' => 'This content is confidential',
            'Priority' => 2,
            'Color' => '#FF0000',
            'ParentId' => null,
            'ContentType' => 'File, Email, Site, UnifiedGroup',
            'Disabled' => false,
            'LabelActions' => json_encode([
                ['Type' => 'encrypt', 'Settings' => []],
            ]),
        ],
        [
            'ImmutableId' => '87654321-1234-1234-1234-123456789012',
            'DisplayName' => 'Confidential - Internal',
            'Name' => 'Confidential/Internal',
            'Comment' => null,
            'Tooltip' => 'Internal only',
            'Priority' => 3,
            'Color' => '',
            'ParentId' => '12345678-1234-1234-1234-123456789012',
            'ContentType' => 'File, Email',
            'Disabled' => false,
            'LabelActions' => '[]',
        ],
    ]);

    $service = app(CompliancePowerShellService::class);
    $labels = $service->parseLabelsOutput($psOutput);

    expect($labels)->toHaveCount(2);

    expect($labels[0]['id'])->toBe('12345678-1234-1234-1234-123456789012');
    expect($labels[0]['name'])->toBe('Confidential');
    expect($labels[0]['description'])->toBe('Apply to confidential data');
    expect($labels[0]['color'])->toBe('#FF0000');
    expect($labels[0]['tooltip'])->toBe('This content is confidential');
    expect($labels[0]['priority'])->toBe(2);
    expect($labels[0]['isActive'])->toBeTrue();
    expect($labels[0]['parent'])->toBeNull();
    expect($labels[0]['contentFormats'])->toContain('file');
    expect($labels[0]['contentFormats'])->toContain('email');
    expect($labels[0]['contentFormats'])->toContain('site');
    expect($labels[0]['protectionSettings']['encryptionEnabled'])->toBeTrue();

    expect($labels[1]['parent']['id'])->toBe('12345678-1234-1234-1234-123456789012');
    expect($labels[1]['contentFormats'])->not->toContain('site');
});

test('parsePoliciesOutput converts PowerShell JSON to policy array', function () {
    $psOutput = json_encode([
        [
            'ImmutableId' => 'policy-1',
            'Name' => 'Global Policy',
            'Labels' => [
                ['ImmutableId' => 'label-1', 'DisplayName' => 'Confidential'],
            ],
            'Settings' => json_encode([
                ['Key' => 'requiredowngradejustification', 'Value' => 'true'],
            ]),
        ],
    ]);

    $service = app(CompliancePowerShellService::class);
    $policies = $service->parsePoliciesOutput($psOutput);

    expect($policies)->toHaveCount(1);
    expect($policies[0]['id'])->toBe('policy-1');
    expect($policies[0]['name'])->toBe('Global Policy');
});

test('parseLabelsOutput handles single label (non-array) PowerShell output', function () {
    $psOutput = json_encode([
        'ImmutableId' => '12345678-1234-1234-1234-123456789012',
        'DisplayName' => 'Public',
        'Name' => 'Public',
        'Comment' => null,
        'Tooltip' => 'Public data',
        'Priority' => 0,
        'Color' => '',
        'ParentId' => null,
        'ContentType' => 'File, Email',
        'Disabled' => false,
        'LabelActions' => '[]',
    ]);

    $service = app(CompliancePowerShellService::class);
    $labels = $service->parseLabelsOutput($psOutput);

    expect($labels)->toHaveCount(1);
    expect($labels[0]['name'])->toBe('Public');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=CompliancePowerShellServiceTest --compact
```

Expected: Fail — class doesn't exist yet.

- [ ] **Step 3: Create CompliancePowerShellService**

Create `app/Services/CompliancePowerShellService.php`:

```php
<?php

namespace App\Services;

use App\Enums\CloudEnvironment;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class CompliancePowerShellService
{
    public function isAvailable(): bool
    {
        $certPath = Setting::get('graph', 'compliance_certificate_path', config('graph.compliance_certificate_path'));

        if (empty($certPath)) {
            return false;
        }

        $result = Process::run($this->findPwshCommand());

        return $result->successful();
    }

    public function getLabels(): array
    {
        $output = $this->runPowerShell('Get-Label -IncludeDetailedLabelActions | ConvertTo-Json -Depth 5 -Compress');

        return $this->parseLabelsOutput($output);
    }

    public function getPolicies(): array
    {
        $output = $this->runPowerShell('Get-LabelPolicy | ConvertTo-Json -Depth 5 -Compress');

        return $this->parsePoliciesOutput($output);
    }

    public function parseLabelsOutput(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return [];
        }

        // PowerShell returns a single object (not array) when there's only one label
        if (isset($data['ImmutableId'])) {
            $data = [$data];
        }

        return array_map(fn (array $item) => $this->mapLabel($item), $data);
    }

    public function parsePoliciesOutput(string $json): array
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return [];
        }

        if (isset($data['ImmutableId'])) {
            $data = [$data];
        }

        return array_map(fn (array $item) => $this->mapPolicy($item), $data);
    }

    private function mapLabel(array $item): array
    {
        $contentFormats = $this->parseContentType($item['ContentType'] ?? '');
        $protectionSettings = $this->parseLabelActions($item['LabelActions'] ?? '[]');

        return [
            'id' => $item['ImmutableId'],
            'name' => $item['DisplayName'],
            'description' => $item['Comment'] ?? null,
            'color' => ! empty($item['Color']) ? $item['Color'] : null,
            'tooltip' => $item['Tooltip'] ?? null,
            'priority' => $item['Priority'] ?? 0,
            'isActive' => ! ($item['Disabled'] ?? false),
            'parent' => ! empty($item['ParentId']) ? ['id' => $item['ParentId']] : null,
            'contentFormats' => $contentFormats,
            'protectionSettings' => $protectionSettings,
        ];
    }

    private function mapPolicy(array $item): array
    {
        $labelIds = [];
        foreach (($item['Labels'] ?? []) as $label) {
            if (! empty($label['ImmutableId'])) {
                $labelIds[] = $label['ImmutableId'];
            }
        }

        return [
            'id' => $item['ImmutableId'] ?? $item['Guid'] ?? '',
            'name' => $item['Name'],
            'settings' => ['labels' => array_map(fn ($id) => ['labelId' => $id], $labelIds)],
            'scopes' => $item['Scopes'] ?? [],
        ];
    }

    private function parseContentType(string $contentType): array
    {
        $formats = [];
        $lower = strtolower($contentType);

        if (str_contains($lower, 'file')) {
            $formats[] = 'file';
        }
        if (str_contains($lower, 'email')) {
            $formats[] = 'email';
        }
        if (str_contains($lower, 'site')) {
            $formats[] = 'site';
        }
        if (str_contains($lower, 'unifiedgroup') || str_contains($lower, 'group')) {
            $formats[] = 'group';
        }

        return $formats;
    }

    private function parseLabelActions(string $actionsJson): array
    {
        $actions = is_string($actionsJson) ? json_decode($actionsJson, true) : $actionsJson;

        if (! is_array($actions)) {
            return [];
        }

        $hasEncryption = false;
        $hasWatermark = false;
        $hasHeader = false;
        $hasFooter = false;

        foreach ($actions as $action) {
            $type = strtolower($action['Type'] ?? '');
            if (str_contains($type, 'encrypt')) {
                $hasEncryption = true;
            }
            if (str_contains($type, 'watermark')) {
                $hasWatermark = true;
            }
            if (str_contains($type, 'header')) {
                $hasHeader = true;
            }
            if (str_contains($type, 'footer')) {
                $hasFooter = true;
            }
        }

        return [
            'encryptionEnabled' => $hasEncryption,
            'watermarkEnabled' => $hasWatermark,
            'headerEnabled' => $hasHeader,
            'footerEnabled' => $hasFooter,
        ];
    }

    private function runPowerShell(string $command): string
    {
        $cloudEnv = CloudEnvironment::tryFrom(
            Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
        ) ?? CloudEnvironment::Commercial;

        $connectionUri = match ($cloudEnv) {
            CloudEnvironment::GccHigh => 'https://ps.compliance.protection.office365.us/powershell-liveid/',
            CloudEnvironment::Commercial => 'https://ps.compliance.protection.office365.com/powershell-liveid/',
        };

        $azureAdUri = match ($cloudEnv) {
            CloudEnvironment::GccHigh => 'https://login.microsoftonline.us/organizations',
            CloudEnvironment::Commercial => 'https://login.microsoftonline.com/organizations',
        };

        $certPath = Setting::get('graph', 'compliance_certificate_path', config('graph.compliance_certificate_path'));
        $certPassword = Setting::get('graph', 'compliance_certificate_password', config('graph.compliance_certificate_password'));
        $clientId = Setting::get('graph', 'client_id', config('graph.client_id'));
        $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));

        $script = <<<'PS'
$ErrorActionPreference = 'Stop'
Import-Module ExchangeOnlineManagement
$certPassword = ConvertTo-SecureString -String $env:PS_CERT_PASSWORD -AsPlainText -Force
Connect-IPPSSession -AppId $env:PS_CLIENT_ID -CertificateFilePath $env:PS_CERT_PATH -CertificatePassword $certPassword -Organization $env:PS_TENANT_ID -ConnectionUri $env:PS_CONNECTION_URI -AzureADAuthorizationEndpointUri $env:PS_AZURE_AD_URI
PS;

        // Append the actual command after the connection setup
        $fullScript = $script . "\n" . $command . "\nDisconnect-ExchangeOnline -Confirm:\$false";

        $result = Process::env([
            'PS_CERT_PASSWORD' => $certPassword,
            'PS_CLIENT_ID' => $clientId,
            'PS_CERT_PATH' => $certPath,
            'PS_TENANT_ID' => $tenantId,
            'PS_CONNECTION_URI' => $connectionUri,
            'PS_AZURE_AD_URI' => $azureAdUri,
        ])->timeout(120)->run(['pwsh', '-NoProfile', '-NonInteractive', '-Command', $fullScript]);

        if ($result->failed()) {
            Log::error('PowerShell compliance command failed', [
                'exitCode' => $result->exitCode(),
                'error' => $result->errorOutput(),
            ]);

            throw new \RuntimeException('PowerShell compliance command failed: '.$result->errorOutput());
        }

        return $result->output();
    }

    private function findPwshCommand(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ['where', 'pwsh'];
        }

        return ['which', 'pwsh'];
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --filter=CompliancePowerShellServiceTest --compact
```

Expected: All 6 tests pass.

- [ ] **Step 5: Run pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/CompliancePowerShellService.php tests/Feature/Services/CompliancePowerShellServiceTest.php
git commit -m "feat: add CompliancePowerShellService for GCC High label sync"
```

---

## Chunk 5: Three-Tier Label Sync in SensitivityLabelService

### Task 8: Make syncLabels() and syncPolicies() graceful with three-tier dispatch

**Files:**
- Modify: `app/Services/SensitivityLabelService.php`
- Modify: `tests/Feature/Services/SensitivityLabelServiceTest.php`

- [ ] **Step 1: Write failing tests for graceful Graph failure and PowerShell fallback**

Add to `tests/Feature/Services/SensitivityLabelServiceTest.php` `beforeEach`:

```php
beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant-id',
        'graph.client_id' => 'test-client-id',
        'graph.client_secret' => 'test-client-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
        'graph.cloud_environment' => 'commercial',
        'graph.sharepoint_tenant' => 'contoso',
        'graph.compliance_certificate_path' => null,
    ]);

    Cache::forget('msgraph_access_token');
    Cache::forget('spo_admin_access_token');
});
```

Add these new tests at the end:

```php
test('syncLabels gracefully handles Graph API failure without deleting existing labels', function () {
    // Pre-existing label from a previous sync
    SensitivityLabel::create([
        'label_id' => 'existing-label',
        'name' => 'Previously Synced',
        'protection_type' => 'encryption',
        'synced_at' => now()->subDay(),
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/security/informationProtection/sensitivityLabels' => Http::response(
            ['error' => ['code' => 'ResourceNotFound', 'message' => 'Resource not found for the segment informationProtection']],
            404
        ),
    ]);

    $service = app(SensitivityLabelService::class);
    $result = $service->syncLabels();

    expect($result['labels_synced'])->toBe(0);
    // Existing label should NOT be deleted
    expect(SensitivityLabel::count())->toBe(1);
    expect(SensitivityLabel::first()->label_id)->toBe('existing-label');
});

test('syncPolicies gracefully handles Graph API failure', function () {
    SensitivityLabelPolicy::create([
        'policy_id' => 'existing-policy',
        'name' => 'Previously Synced Policy',
        'target_type' => 'all_users',
        'synced_at' => now()->subDay(),
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/security/informationProtection/sensitivityLabels/policies' => Http::response(
            ['error' => ['code' => 'ResourceNotFound']],
            404
        ),
    ]);

    $service = app(SensitivityLabelService::class);
    $result = $service->syncPolicies();

    expect($result)->toBe(0);
    // Existing policy should NOT be deleted
    expect(SensitivityLabelPolicy::count())->toBe(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --filter="syncLabels gracefully|syncPolicies gracefully" --compact
```

Expected: Fail — current code throws exceptions on Graph API errors.

- [ ] **Step 3: Add CompliancePowerShellService to constructor**

In `app/Services/SensitivityLabelService.php`, update the constructor (line 16-19):

```php
    public function __construct(
        private MicrosoftGraphService $graph,
        private SharePointAdminService $spoAdmin,
        private CompliancePowerShellService $compliance,
    ) {}
```

Add the import at the top:

```php
use App\Services\CompliancePowerShellService;
```

- [ ] **Step 4: Rewrite syncLabels() with three-tier dispatch**

Replace the `syncLabels()` method (lines 21-51) with:

```php
    public function syncLabels(): array
    {
        $graphLabels = $this->fetchLabelsWithFallback();

        if ($graphLabels === null) {
            // All tiers failed or unavailable — skip sync, preserve existing labels
            return ['labels_synced' => 0, 'source' => 'unavailable'];
        }

        $syncedLabelIds = [];
        $source = $graphLabels['source'];
        $labels = $graphLabels['labels'];

        // First pass: create/update all labels (without parent references)
        foreach ($labels as $graphLabel) {
            $parsed = $this->parseLabel($graphLabel);
            $label = SensitivityLabel::updateOrCreate(
                ['label_id' => $graphLabel['id']],
                array_merge($parsed, ['synced_at' => now()])
            );
            $syncedLabelIds[] = $label->id;
        }

        // Second pass: link parent-child relationships
        foreach ($labels as $graphLabel) {
            if (! empty($graphLabel['parent']['id'])) {
                $parent = SensitivityLabel::where('label_id', $graphLabel['parent']['id'])->first();
                if ($parent) {
                    SensitivityLabel::where('label_id', $graphLabel['id'])
                        ->update(['parent_label_id' => $parent->id]);
                }
            }
        }

        // Only delete stale labels when we got a definitive list (Tier 1 or 2)
        if (in_array($source, ['graph', 'powershell'])) {
            SensitivityLabel::whereNotIn('id', $syncedLabelIds)->delete();
        }

        return ['labels_synced' => count($syncedLabelIds), 'source' => $source];
    }
```

- [ ] **Step 5: Add fetchLabelsWithFallback() method**

Add after `syncLabels()`:

```php
    private function fetchLabelsWithFallback(): ?array
    {
        // Tier 1: Graph API
        try {
            $labels = $this->fetchLabelsFromGraph();

            return ['labels' => $labels, 'source' => 'graph'];
        } catch (\Throwable $e) {
            Log::warning("Graph API label fetch failed: {$e->getMessage()}");
        }

        // Tier 2: PowerShell
        try {
            if ($this->compliance->isAvailable()) {
                $labels = $this->compliance->getLabels();

                return ['labels' => $labels, 'source' => 'powershell'];
            }
        } catch (\Throwable $e) {
            Log::warning("PowerShell label fetch failed: {$e->getMessage()}");
        }

        // Tier 3: Stubs will be created during syncSiteLabels()
        Log::warning('No label source available — labels will be created as stubs from site data');

        return null;
    }
```

- [ ] **Step 6: Rewrite syncPolicies() with graceful fallback**

Replace `syncPolicies()` (lines 53-71) with:

```php
    public function syncPolicies(): int
    {
        $graphPolicies = $this->fetchPoliciesWithFallback();

        if ($graphPolicies === null) {
            return 0;
        }

        $syncedIds = [];
        $source = $graphPolicies['source'];
        $policies = $graphPolicies['policies'];

        foreach ($policies as $graphPolicy) {
            $parsed = $this->parsePolicy($graphPolicy);
            $policy = SensitivityLabelPolicy::updateOrCreate(
                ['policy_id' => $graphPolicy['id']],
                array_merge($parsed, ['synced_at' => now()])
            );
            $syncedIds[] = $policy->id;
        }

        if (in_array($source, ['graph', 'powershell'])) {
            SensitivityLabelPolicy::whereNotIn('id', $syncedIds)->delete();
        }

        return count($syncedIds);
    }

    private function fetchPoliciesWithFallback(): ?array
    {
        // Tier 1: Graph API
        try {
            $policies = $this->fetchLabelPoliciesFromGraph();

            return ['policies' => $policies, 'source' => 'graph'];
        } catch (\Throwable $e) {
            Log::warning("Graph API policy fetch failed: {$e->getMessage()}");
        }

        // Tier 2: PowerShell
        try {
            if ($this->compliance->isAvailable()) {
                $policies = $this->compliance->getPolicies();

                return ['policies' => $policies, 'source' => 'powershell'];
            }
        } catch (\Throwable $e) {
            Log::warning("PowerShell policy fetch failed: {$e->getMessage()}");
        }

        Log::warning('No policy source available — skipping policy sync');

        return null;
    }
```

- [ ] **Step 7: Run the failing tests again**

```bash
php artisan test --filter="syncLabels gracefully|syncPolicies gracefully" --compact
```

Expected: Both tests pass.

- [ ] **Step 8: Run all sensitivity label tests**

```bash
php artisan test --filter=SensitivityLabelServiceTest --compact
```

Expected: All tests pass (existing + new).

- [ ] **Step 9: Commit**

```bash
git add app/Services/SensitivityLabelService.php tests/Feature/Services/SensitivityLabelServiceTest.php
git commit -m "feat: three-tier label sync with graceful Graph API failure handling"
```

### Task 9: Add Groups API label discovery and stub auto-creation to syncSiteLabels()

**Files:**
- Modify: `app/Services/SensitivityLabelService.php`
- Modify: `tests/Feature/Services/SensitivityLabelServiceTest.php`

- [ ] **Step 1: Write failing test for stub auto-creation**

Add to `tests/Feature/Services/SensitivityLabelServiceTest.php`:

```php
test('syncSiteLabels auto-creates label stubs from group data when label not in DB', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        // Sites endpoint
        'graph.microsoft.com/v1.0/sites*' => Http::response([
            'value' => [
                [
                    'id' => 'site-1',
                    'displayName' => 'Project Alpha',
                    'name' => 'alpha',
                    'webUrl' => 'https://contoso.sharepoint.com/sites/alpha',
                ],
            ],
        ]),
        // Groups endpoint — returns a group with an assigned label
        'graph.microsoft.com/v1.0/groups*' => Http::response([
            'value' => [
                [
                    'id' => 'group-1',
                    'displayName' => 'Project Alpha',
                    'assignedLabels' => [
                        ['labelId' => 'new-label-guid', 'displayName' => 'Highly Confidential'],
                    ],
                ],
            ],
        ]),
        // Group root site
        'graph.microsoft.com/v1.0/groups/group-1/sites/root*' => Http::response([
            'id' => 'site-1',
            'webUrl' => 'https://contoso.sharepoint.com/sites/alpha',
        ]),
        // Per-site label (fallback)
        'graph.microsoft.com/v1.0/sites/site-1/sensitivityLabel' => Http::response(
            ['error' => ['code' => 'ResourceNotFound']],
            404
        ),
    ]);

    config(['graph.sharepoint_tenant' => null]); // SPO Admin not configured

    $service = app(SensitivityLabelService::class);
    $count = $service->syncSiteLabels();

    expect($count)->toBe(1);

    // Label stub should have been auto-created
    $label = SensitivityLabel::where('label_id', 'new-label-guid')->first();
    expect($label)->not->toBeNull();
    expect($label->name)->toBe('Highly Confidential');
    expect($label->protection_type)->toBe('unknown');

    // Site label mapping should exist
    $siteLabel = SiteSensitivityLabel::where('site_id', 'site-1')->first();
    expect($siteLabel)->not->toBeNull();
    expect($siteLabel->sensitivity_label_id)->toBe($label->id);
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test --filter="syncSiteLabels auto-creates" --compact
```

Expected: Fails — current syncSiteLabels doesn't query groups.

- [ ] **Step 3: Add fetchGroupLabelMap() method**

Add to `app/Services/SensitivityLabelService.php`:

```php
    private function fetchGroupLabelMap(): array
    {
        try {
            $groupsResponse = $this->graph->get('/groups', [
                '$filter' => "groupTypes/any(g:g eq 'Unified')",
                '$select' => 'id,displayName,assignedLabels',
                '$top' => 999,
            ]);

            $groups = $groupsResponse['value'] ?? [];
            $map = []; // siteId → ['labelId' => ..., 'labelName' => ...]

            foreach ($groups as $group) {
                $assignedLabels = $group['assignedLabels'] ?? [];
                if (empty($assignedLabels)) {
                    continue;
                }

                $label = $assignedLabels[0]; // Sites only have one label

                try {
                    $rootSite = $this->graph->get("/groups/{$group['id']}/sites/root", [
                        '$select' => 'id,webUrl',
                    ]);

                    $map[$rootSite['id']] = [
                        'labelId' => $label['labelId'],
                        'labelName' => $label['displayName'] ?? null,
                    ];
                } catch (\Throwable $e) {
                    Log::debug("Could not fetch root site for group {$group['id']}: {$e->getMessage()}");
                }
            }

            return $map;
        } catch (\Throwable $e) {
            Log::warning("Failed to fetch group label map: {$e->getMessage()}");

            return [];
        }
    }
```

- [ ] **Step 4: Rewrite syncSiteLabels() with group label discovery and stub creation**

Replace `syncSiteLabels()` (lines 73-112) with:

```php
    public function syncSiteLabels(): int
    {
        $sites = $this->fetchSitesFromGraph();
        $sharingMap = $this->fetchSharingCapabilities();
        $groupLabelMap = $this->fetchGroupLabelMap();
        $syncedIds = [];

        foreach ($sites as $site) {
            // Try to find the label for this site
            $labelId = null;
            $labelName = null;

            // Source 1: Group label map (most reliable in GCC High)
            if (isset($groupLabelMap[$site['id']])) {
                $labelId = $groupLabelMap[$site['id']]['labelId'];
                $labelName = $groupLabelMap[$site['id']]['labelName'];
            }

            // Source 2: SPO REST per-site call (for non-group sites)
            if (! $labelId) {
                $spoLabel = $this->fetchSiteLabelFromSpoRest($site['webUrl'] ?? '');
                if ($spoLabel) {
                    $labelId = $spoLabel['id'];
                    $labelName = $spoLabel['name'] ?? $labelName;
                }
            }

            // Source 3: Per-site Graph call (last resort)
            if (! $labelId) {
                $labelId = $this->fetchSiteLabelFromGraph($site['id']);
            }

            if (! $labelId) {
                continue;
            }

            // Find or auto-create the label
            $label = SensitivityLabel::where('label_id', $labelId)->first();

            if (! $label) {
                $label = SensitivityLabel::create([
                    'label_id' => $labelId,
                    'name' => $labelName ?? 'Unknown Label',
                    'protection_type' => 'unknown',
                    'synced_at' => now(),
                ]);
            }

            $siteUrl = strtolower(rtrim($site['webUrl'] ?? '', '/'));
            $siteData = $sharingMap[$siteUrl] ?? [];
            $sharingCapability = $siteData['sharingCapability'] ?? 'Disabled';
            $externalSharing = in_array($sharingCapability, [
                'ExternalUserSharingOnly', 'ExternalUserAndGuestSharing', 'ExistingExternalUserSharingOnly',
            ]);

            $record = SiteSensitivityLabel::updateOrCreate(
                ['site_id' => $site['id']],
                [
                    'site_name' => $site['displayName'] ?? $site['name'] ?? 'Unknown',
                    'site_url' => $site['webUrl'] ?? '',
                    'sensitivity_label_id' => $label->id,
                    'external_sharing_enabled' => $externalSharing,
                    'synced_at' => now(),
                ]
            );
            $syncedIds[] = $record->id;
        }

        SiteSensitivityLabel::whereNotIn('id', $syncedIds)->delete();

        return count($syncedIds);
    }
```

- [ ] **Step 4b: Add fetchSiteLabelFromSpoRest() method**

Add to `app/Services/SensitivityLabelService.php`:

```php
    private function fetchSiteLabelFromSpoRest(string $siteUrl): ?array
    {
        if (empty($siteUrl) || ! $this->spoAdmin->isConfigured()) {
            return null;
        }

        try {
            $token = $this->spoAdmin->getAccessToken();

            $response = Http::withToken($token)
                ->acceptJson()
                ->get("{$siteUrl}/_api/site/SensitivityLabelInfo");

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();
            $labelId = $data['Id'] ?? null;

            // Skip empty/zero GUIDs
            if (empty($labelId) || $labelId === '00000000-0000-0000-0000-000000000000') {
                return null;
            }

            return [
                'id' => $labelId,
                'name' => $data['DisplayName'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::debug("SPO REST label fetch failed for {$siteUrl}: {$e->getMessage()}");

            return null;
        }
    }
```

Add the Http import at the top if not already present:

```php
use Illuminate\Support\Facades\Http;
```

- [ ] **Step 5: Run the auto-create test**

```bash
php artisan test --filter="syncSiteLabels auto-creates" --compact
```

Expected: Pass.

- [ ] **Step 6: Run all sensitivity label tests**

```bash
php artisan test --filter=SensitivityLabelServiceTest --compact
```

Expected: All tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Services/SensitivityLabelService.php tests/Feature/Services/SensitivityLabelServiceTest.php
git commit -m "feat: add Groups API label discovery and stub auto-creation"
```

---

## Chunk 6: User Information List for Per-Site Guest Mapping

### Task 10: Add syncSiteExternalUsers() to SharePointSiteService

**Files:**
- Modify: `app/Services/SharePointSiteService.php`
- Modify: `tests/Feature/Services/SharePointSiteServiceTest.php`

- [ ] **Step 1: Write failing test**

Add to `tests/Feature/Services/SharePointSiteServiceTest.php`:

```php
test('syncSiteExternalUsers maps external users from User Information List', function () {
    // Create a site and a guest user to match against
    $site = SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    $guest = GuestUser::factory()->create([
        'email' => 'john@fabrikam.com',
        'user_principal_name' => 'john_fabrikam.com#EXT#@contoso.onmicrosoft.com',
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        // User Information List
        'graph.microsoft.com/v1.0/sites/site-1/lists/User%20Information%20List/items*' => Http::response([
            'value' => [
                [
                    'fields' => [
                        'UserName' => 'i:0#.f|membership|john_fabrikam.com#EXT#@contoso.onmicrosoft.com',
                        'Name' => 'i:0#.f|membership|john_fabrikam.com#EXT#@contoso.onmicrosoft.com',
                        'EMail' => 'john@fabrikam.com',
                        'Title' => 'John External',
                    ],
                ],
                [
                    'fields' => [
                        'UserName' => 'i:0#.f|membership|internal@contoso.com',
                        'Name' => 'i:0#.f|membership|internal@contoso.com',
                        'EMail' => 'internal@contoso.com',
                        'Title' => 'Internal User',
                    ],
                ],
            ],
        ]),
    ]);

    $service = app(SharePointSiteService::class);
    $count = $service->syncSiteExternalUsers();

    expect($count)->toBe(1);
    expect(SharePointSitePermission::count())->toBe(1);

    $perm = SharePointSitePermission::first();
    expect($perm->sharepoint_site_id)->toBe($site->id);
    expect($perm->guest_user_id)->toBe($guest->id);
    expect($perm->granted_via)->toBe('site_access');
});

test('syncSiteExternalUsers skips when sync_site_users config is false', function () {
    config(['graph.sync_site_users' => false]);

    Http::fake();

    SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Test Site',
        'url' => 'https://contoso.sharepoint.com/sites/test',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    $service = app(SharePointSiteService::class);
    $count = $service->syncSiteExternalUsers();

    expect($count)->toBe(0);
    Http::assertNothingSent();
});
```

Add to the test file's imports:

```php
use App\Models\GuestUser;
use App\Models\SharePointSitePermission;
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test --filter="syncSiteExternalUsers" --compact
```

Expected: Fail — method doesn't exist.

- [ ] **Step 3: Implement syncSiteExternalUsers()**

Add to `app/Services/SharePointSiteService.php`:

```php
    public function syncSiteExternalUsers(): int
    {
        if (! config('graph.sync_site_users', true)) {
            return 0;
        }

        $sites = SharePointSite::all();
        $totalMapped = 0;

        foreach ($sites as $site) {
            try {
                $externalUsers = $this->fetchExternalUsersFromInfoList($site->site_id);

                foreach ($externalUsers as $externalUser) {
                    $guest = GuestUser::where('email', $externalUser['email'])
                        ->orWhere('user_principal_name', $externalUser['userPrincipalName'])
                        ->first();

                    if (! $guest) {
                        continue;
                    }

                    SharePointSitePermission::updateOrCreate(
                        [
                            'sharepoint_site_id' => $site->id,
                            'guest_user_id' => $guest->id,
                            'role' => 'member',
                            'granted_via' => 'site_access',
                        ]
                    );

                    $totalMapped++;
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to fetch User Information List for site {$site->site_id}: {$e->getMessage()}");
            }
        }

        return $totalMapped;
    }

    private function fetchExternalUsersFromInfoList(string $siteId): array
    {
        $externalUsers = [];
        $url = "/sites/{$siteId}/lists/User Information List/items";
        $params = [
            '$expand' => 'fields',
            '$top' => 999,
        ];

        do {
            $response = $this->graph->get($url, $params);
            $items = $response['value'] ?? [];

            foreach ($items as $item) {
                $fields = $item['fields'] ?? [];
                $userName = $fields['UserName'] ?? $fields['Name'] ?? '';

                if (! str_contains($userName, '#EXT#')) {
                    continue;
                }

                $email = $fields['EMail'] ?? $this->extractEmailFromLoginName($userName);

                if ($email) {
                    $externalUsers[] = [
                        'email' => strtolower($email),
                        'userPrincipalName' => $userName,
                        'displayName' => $fields['Title'] ?? '',
                    ];
                }
            }

            // Handle pagination
            $nextLink = $response['@odata.nextLink'] ?? null;
            if ($nextLink) {
                $parsed = parse_url($nextLink);
                parse_str($parsed['query'] ?? '', $params);
                $url = $parsed['path'] ?? $url;
            }
        } while ($nextLink && count($externalUsers) < 5000);

        return $externalUsers;
    }

    private function extractEmailFromLoginName(string $loginName): ?string
    {
        // Format: i:0#.f|membership|john_fabrikam.com#EXT#@contoso.onmicrosoft.com
        if (preg_match('/\|([^|]+)#EXT#/', $loginName, $matches)) {
            $encoded = $matches[1];
            // Replace last underscore before domain with @
            $lastUnderscore = strrpos($encoded, '_');
            if ($lastUnderscore !== false) {
                return substr_replace($encoded, '@', $lastUnderscore, 1);
            }
        }

        return null;
    }
```

Add imports at the top of the file:

```php
use App\Models\GuestUser;
use App\Models\SharePointSitePermission;
```

- [ ] **Step 4: Run the tests**

```bash
php artisan test --filter="syncSiteExternalUsers" --compact
```

Expected: Both tests pass.

- [ ] **Step 5: Run all SharePoint tests**

```bash
php artisan test --filter=SharePointSiteServiceTest --compact
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Services/SharePointSiteService.php tests/Feature/Services/SharePointSiteServiceTest.php
git commit -m "feat: add User Information List sync for per-site external user mapping"
```

---

## Chunk 7: Console Command Updates

### Task 11: Update SyncSensitivityLabels command

**Files:**
- Modify: `app/Console/Commands/SyncSensitivityLabels.php`

- [ ] **Step 1: Update command to report tier used**

In `app/Console/Commands/SyncSensitivityLabels.php`, update the handle method. The `syncLabels()` now returns `['labels_synced' => int, 'source' => string]`.

Replace lines 26-28 (the preamble + syncLabels + info) with:

```php
            $this->info('Syncing sensitivity labels...');
            $labelResult = $service->syncLabels();
            $labelSource = $labelResult['source'] ?? 'graph';
            $this->info("  Labels synced: {$labelResult['labels_synced']} (via {$labelSource})");
```

Also update the `$description` (line 15) to:

```php
    protected $description = 'Sync sensitivity labels, policies, and site assignments from Graph API, PowerShell, or site discovery';
```

- [ ] **Step 2: Commit**

```bash
git add app/Console/Commands/SyncSensitivityLabels.php
git commit -m "feat: report label sync tier in sensitivity-labels command output"
```

### Task 12: Update SyncSharePointSites command

**Files:**
- Modify: `app/Console/Commands/SyncSharePointSites.php`

- [ ] **Step 1: Add external user sync step**

In `app/Console/Commands/SyncSharePointSites.php`, add after the `syncPermissions()` call (around line 32):

```php
            $externalUsersCount = $service->syncSiteExternalUsers();
            $this->info("  External users mapped: {$externalUsersCount} (via User Information List)");
```

Update the records_synced total in the SyncLog update to include the new count.

- [ ] **Step 2: Commit**

```bash
git add app/Console/Commands/SyncSharePointSites.php
git commit -m "feat: add external user sync step to sharepoint-sites command"
```

---

## Chunk 8: Admin UI — Certificate Settings

### Task 13: Update admin settings for compliance certificate

**Files:**
- Modify: `app/Http/Requests/UpdateGraphSettingsRequest.php`
- Modify: `app/Http/Controllers/Admin/AdminGraphController.php`
- Modify: `resources/js/pages/admin/Graph.vue`

- [ ] **Step 1: Add validation rules**

In `app/Http/Requests/UpdateGraphSettingsRequest.php`, add to rules array:

```php
'compliance_certificate_path' => ['nullable', 'string', 'max:500'],
'compliance_certificate_password' => ['nullable', 'string', 'max:255'],
```

- [ ] **Step 2: Update controller edit() to pass new settings**

In `app/Http/Controllers/Admin/AdminGraphController.php`, add to the settings array in `edit()`:

```php
'compliance_certificate_path' => Setting::get('graph', 'compliance_certificate_path', config('graph.compliance_certificate_path')),
'compliance_certificate_password' => Setting::get('graph', 'compliance_certificate_password') ? '********' : '',
```

- [ ] **Step 3: Update controller update() to save new settings**

In `update()`, add after the existing Setting::set calls:

```php
Setting::set('graph', 'compliance_certificate_path', $validated['compliance_certificate_path'] ?? '');

if (! empty($validated['compliance_certificate_password']) && $validated['compliance_certificate_password'] !== '********') {
    Setting::set('graph', 'compliance_certificate_password', $validated['compliance_certificate_password'], encrypted: true);
}
```

- [ ] **Step 4: Update Graph.vue — add props type, form data, and template fields**

In `resources/js/pages/admin/Graph.vue`:

Add to the settings type definition:

```typescript
compliance_certificate_path: string;
compliance_certificate_password: string;
```

Add to form initialization:

```typescript
compliance_certificate_path: props.settings.compliance_certificate_path ?? '',
compliance_certificate_password: props.settings.compliance_certificate_password ?? '',
```

Add template fields after the SharePoint Tenant Slug field, wrapped in a `v-if="form.cloud_environment === 'gcc_high'"` condition:

```vue
<div v-if="form.cloud_environment === 'gcc_high'" class="space-y-4">
    <div class="grid gap-2">
        <Label for="compliance_certificate_path">Compliance Certificate Path (PFX)</Label>
        <Input
            id="compliance_certificate_path"
            v-model="form.compliance_certificate_path"
            placeholder="/path/to/certificate.pfx"
        />
        <p class="text-muted-foreground text-xs">
            Path to the PFX certificate file for PowerShell compliance module authentication.
            Required for full sensitivity label sync in GCC High.
        </p>
        <InputError :message="form.errors.compliance_certificate_path" />
    </div>

    <div class="grid gap-2">
        <Label for="compliance_certificate_password">Compliance Certificate Password</Label>
        <Input
            id="compliance_certificate_password"
            v-model="form.compliance_certificate_password"
            type="password"
            placeholder="Certificate password"
        />
        <InputError :message="form.errors.compliance_certificate_password" />
    </div>
</div>
```

- [ ] **Step 5: Run pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/UpdateGraphSettingsRequest.php app/Http/Controllers/Admin/AdminGraphController.php resources/js/pages/admin/Graph.vue
git commit -m "feat: add compliance certificate settings to admin UI (GCC High)"
```

---

## Chunk 9: Display UI — Access Controls & Stub Banner

### Task 14: Update TypeScript types

**Files:**
- Modify: `resources/js/types/sharepoint.ts`
- Modify: `resources/js/types/sensitivity-label.ts`

- [ ] **Step 1: Add new fields to SharePointSite type**

In `resources/js/types/sharepoint.ts`, add to the `SharePointSite` type:

```typescript
sharing_domain_restriction_mode: string | null;
sharing_allowed_domain_list: string | null;
sharing_blocked_domain_list: string | null;
default_sharing_link_type: string | null;
default_link_permission: string | null;
external_user_expiration_days: number | null;
override_tenant_expiration_policy: boolean;
conditional_access_policy: string | null;
allow_editing: boolean;
limited_access_file_type: string | null;
allow_downloading_non_web_viewable: boolean;
```

Add `'site_access'` to the `granted_via` union type in `SharePointSitePermission`:

```typescript
granted_via: 'direct' | 'sharing_link' | 'group_membership' | 'site_access';
```

- [ ] **Step 2: Add 'unknown' to protection_type in SensitivityLabel**

In `resources/js/types/sensitivity-label.ts`, update the `protection_type` field:

```typescript
protection_type: 'encryption' | 'watermark' | 'header_footer' | 'none' | 'unknown';
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/types/sharepoint.ts resources/js/types/sensitivity-label.ts
git commit -m "feat: update TypeScript types for access controls and unknown protection"
```

### Task 15: Update SharePoint Sites pages

**Files:**
- Modify: `resources/js/pages/sharepoint-sites/Index.vue`
- Modify: `resources/js/pages/sharepoint-sites/Show.vue`

- [ ] **Step 1: Add access control column to Index**

In `resources/js/pages/sharepoint-sites/Index.vue`, add a helper function:

```typescript
function accessPolicyLabel(policy: string | null): string {
    const labels: Record<string, string> = {
        AllowFullAccess: 'Full Access',
        AllowLimitedAccess: 'Limited Access',
        BlockAccess: 'Blocked',
        AuthenticationContext: 'Auth Context',
    };
    return labels[policy ?? ''] ?? 'Full Access';
}

function accessPolicyVariant(policy: string | null): 'default' | 'secondary' | 'destructive' | 'outline' {
    const variants: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
        AllowFullAccess: 'outline',
        AllowLimitedAccess: 'secondary',
        BlockAccess: 'destructive',
    };
    return variants[policy ?? ''] ?? 'outline';
}
```

Add a table column after the existing columns:

```vue
<TableHead>Access Policy</TableHead>
```

And the corresponding cell:

```vue
<TableCell>
    <Badge :variant="accessPolicyVariant(site.conditional_access_policy)">
        {{ accessPolicyLabel(site.conditional_access_policy) }}
    </Badge>
</TableCell>
```

- [ ] **Step 2: Add access control details to Show page**

In `resources/js/pages/sharepoint-sites/Show.vue`, add an "Access Controls" card after the Site Details card:

```vue
<Card>
    <CardHeader>
        <CardTitle>Access Controls</CardTitle>
    </CardHeader>
    <CardContent class="grid gap-4 sm:grid-cols-2">
        <div>
            <p class="text-muted-foreground text-sm">Conditional Access</p>
            <Badge :variant="accessPolicyVariant(site.conditional_access_policy)">
                {{ accessPolicyLabel(site.conditional_access_policy) }}
            </Badge>
        </div>
        <div>
            <p class="text-muted-foreground text-sm">Allow Editing</p>
            <p>{{ site.allow_editing ? 'Yes' : 'No' }}</p>
        </div>
        <div>
            <p class="text-muted-foreground text-sm">Limited Access File Type</p>
            <p>{{ site.limited_access_file_type ?? 'Default' }}</p>
        </div>
        <div>
            <p class="text-muted-foreground text-sm">Allow Download (Non-Viewable)</p>
            <p>{{ site.allow_downloading_non_web_viewable ? 'Yes' : 'No' }}</p>
        </div>
        <div v-if="site.sharing_domain_restriction_mode && site.sharing_domain_restriction_mode !== 'None'">
            <p class="text-muted-foreground text-sm">Domain Restriction</p>
            <p>{{ site.sharing_domain_restriction_mode }}: {{ site.sharing_allowed_domain_list || site.sharing_blocked_domain_list }}</p>
        </div>
        <div v-if="site.external_user_expiration_days">
            <p class="text-muted-foreground text-sm">External User Expiration</p>
            <p>{{ site.external_user_expiration_days }} days{{ site.override_tenant_expiration_policy ? ' (site override)' : '' }}</p>
        </div>
    </CardContent>
</Card>
```

Add the helper functions (same as Index) and update the `grantedViaLabel` function to handle the new `'site_access'` value:

```typescript
function grantedViaLabel(via: string): string {
    const labels: Record<string, string> = {
        direct: 'Direct',
        sharing_link: 'Sharing Link',
        group_membership: 'Group Membership',
        site_access: 'Site Access',
    };
    return labels[via] ?? via;
}
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/sharepoint-sites/Index.vue resources/js/pages/sharepoint-sites/Show.vue
git commit -m "feat: display access control data on SharePoint site pages"
```

### Task 16: Update Sensitivity Labels Index — stub banner

**Files:**
- Modify: `resources/js/pages/sensitivity-labels/Index.vue`

- [ ] **Step 1: Add stub banner and handle unknown protection**

In `resources/js/pages/sensitivity-labels/Index.vue`:

Add a computed property to check if any labels are stubs:

```typescript
const hasStubLabels = computed(() =>
    props.labels.data.some(l => l.protection_type === 'unknown')
);
```

Add a banner before the table (using the existing yellow div pattern from the codebase, since the Alert component doesn't have a `warning` variant):

```vue
<div v-if="hasStubLabels" class="mb-4 rounded-md border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-700 dark:bg-yellow-950 dark:text-yellow-200">
    Some label details are limited — full definitions are unavailable in this cloud environment.
    Labels marked as "Unknown" protection were auto-discovered from site assignments.
</div>
```

Update the `protectionLabel` helper to handle `'unknown'`:

```typescript
function protectionLabel(type: string): string {
    const labels: Record<string, string> = {
        encryption: 'Encryption',
        watermark: 'Watermark',
        header_footer: 'Header/Footer',
        none: 'None',
        unknown: 'Unknown',
    };
    return labels[type] ?? type;
}

function protectionVariant(type: string): string {
    const variants: Record<string, string> = {
        encryption: 'default',
        watermark: 'secondary',
        header_footer: 'secondary',
        none: 'outline',
        unknown: 'outline',
    };
    return variants[type] ?? 'outline';
}
```

Import `Alert` and `AlertDescription` components and `computed` from Vue.

- [ ] **Step 2: Commit**

```bash
git add resources/js/pages/sensitivity-labels/Index.vue
git commit -m "feat: add stub label banner and unknown protection badge"
```

---

## Chunk 10: Docker & Final Verification

### Task 17: Update Dockerfile for PowerShell

**Files:**
- Modify: `Dockerfile`

- [ ] **Step 1: Add PowerShell installation to the production stage**

In the `Dockerfile`, in the production stage (after the existing `apt-get install` line, around line 42), add:

```dockerfile
# Install PowerShell for compliance module (GCC High label sync)
RUN apt-get update && apt-get install -y wget apt-transport-https software-properties-common \
    && wget -q "https://packages.microsoft.com/config/debian/12/packages-microsoft-prod.deb" \
    && dpkg -i packages-microsoft-prod.deb \
    && rm packages-microsoft-prod.deb \
    && apt-get update \
    && apt-get install -y powershell \
    && pwsh -Command "Install-Module ExchangeOnlineManagement -Force -Scope AllUsers -AcceptLicense" \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
```

- [ ] **Step 2: Commit**

```bash
git add Dockerfile
git commit -m "feat: add PowerShell and ExchangeOnlineManagement to Docker image"
```

### Task 18: Run full test suite

- [ ] **Step 1: Run pint on all changed files**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 2: Run full test suite**

```bash
php artisan test --compact
```

Expected: All tests pass.

- [ ] **Step 3: Run frontend checks**

```bash
npm run types:check
npm run lint
```

Expected: No errors.

- [ ] **Step 4: Final commit if pint made changes**

```bash
git add app/ tests/ resources/ config/ database/
git commit -m "style: apply code formatting"
```

---

## Verification Checklist

1. `php artisan test --filter=SharePointAdminServiceTest` — enriched data parsing
2. `php artisan test --filter=CompliancePowerShellServiceTest` — PowerShell output parsing
3. `php artisan test --filter=SharePointSiteServiceTest` — enriched data storage + User Info List
4. `php artisan test --filter=SensitivityLabelServiceTest` — three-tier sync + stub creation
5. `php artisan test --compact` — full suite passes
6. `npm run types:check` — TypeScript types valid
7. `php artisan sync:sharepoint-sites` — syncs with enriched data + external users
8. `php artisan sync:sensitivity-labels` — reports tier used, graceful on GCC High
9. Admin settings page shows certificate fields when GCC High selected
10. SharePoint Sites pages show access control data
11. Sensitivity Labels page shows stub banner when applicable
