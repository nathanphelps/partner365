# Design: GCC High Feature Parity for SharePoint & Sensitivity Labels

## Problem

Partner365 relies on Microsoft Graph API endpoints that are unavailable in GCC High (US Government L4). This creates a degraded experience for GCC High tenants across four areas:

1. **External users with access to SP sites** — Graph `/sites/{siteId}/permissions` only returns app-level permissions, not site users/guests
2. **Sensitivity labels applied to each site** — Graph label definition and per-site label endpoints are blocked
3. **Sharing settings per site** — Only `SharingCapability` is captured; richer controls are ignored
4. **Read-only caps targeted to external users** — Conditional access and editing restrictions not captured

## Goal

Full feature parity between Commercial and GCC High tenants for all four areas. GCC High users should see the same data in the UI, sourced from alternative APIs where Graph is unavailable.

---

## Architecture

### API Availability Matrix

| API Endpoint | Commercial | GCC High |
|---|---|---|
| Graph `/security/informationProtection/sensitivityLabels` | Yes | No |
| Graph `/security/dataSecurityAndGovernance/sensitivityLabels` | Yes | No |
| Graph `/groups/{id}?$select=assignedLabels` | Yes | Yes |
| Graph `/sites/{id}/lists/User Information List/items` | Yes | Yes |
| SPO Admin `GetSitePropertiesFromSharePointByFilters` | Yes | Yes |
| SPO REST `/_api/site/SensitivityLabelInfo` | Yes | Likely (undocumented) |
| PowerShell `Get-Label` via `Connect-IPPSSession` | Yes | Yes |
| PowerShell `Get-LabelPolicy` via `Connect-IPPSSession` | Yes | Yes |

### Three-Tier Label Sync Strategy

```
Tier 1: Graph API (commercial)
  GET /security/informationProtection/sensitivityLabels
  → Full label data: name, color, tooltip, protection, priority, sublabels

Tier 2: PowerShell Get-Label (GCC High, when pwsh available)
  Connect-IPPSSession → Get-Label -IncludeDetailedLabelActions
  → Full label data: same richness as Graph API

Tier 3: Graph Groups + SPO REST (fallback)
  GET /groups?$select=assignedLabels + /_api/site/SensitivityLabelInfo
  → Stubs only: GUID + display name, protection_type = 'unknown'
```

Dispatch logic in `syncLabels()`:
1. Try Tier 1 → on success, done
2. On Graph failure → check if PowerShell is available → try Tier 2
3. If PowerShell unavailable → use Tier 3 (stubs from encountered labels)

Stale cleanup: only delete labels when Tier 1 or 2 succeeds. Tier 3 never deletes (stubs accumulate).

---

## Component Design

### 1. Enriched SPO Admin API Data

**File:** `app/Services/SharePointAdminService.php`

`getSiteProperties()` currently returns `array<normalizedUrl, string>` (URL → sharing capability). Change to return full site property data:

```php
// New return shape per site
$results[$normalizedUrl] = [
    // Sharing controls
    'sharingCapability' => 'ExternalUserSharingOnly',
    'sharingDomainRestrictionMode' => 'AllowList',
    'sharingAllowedDomainList' => 'contoso.com fabrikam.com',
    'sharingBlockedDomainList' => '',
    'defaultSharingLinkType' => 'Direct',
    'defaultLinkPermission' => 'View',

    // External user expiration
    'externalUserExpirationInDays' => 90,
    'overrideTenantExternalUserExpirationPolicy' => true,

    // Access restrictions (read-only caps)
    'conditionalAccessPolicy' => 'AllowLimitedAccess',
    'allowEditing' => false,
    'limitedAccessFileType' => 'WebPreviewableFiles',
    'allowDownloadingNonWebViewableFiles' => false,
];
```

All fields already present in the API response — we just start reading them.

**Enum mappings:**

```php
private const SHARING_MAP = [
    0 => 'Disabled',
    1 => 'ExternalUserSharingOnly',
    2 => 'ExternalUserAndGuestSharing',
    3 => 'ExistingExternalUserSharingOnly',
];

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

### 2. CompliancePowerShellService (New)

**File:** `app/Services/CompliancePowerShellService.php`

Wraps PowerShell `Get-Label` and `Get-LabelPolicy` for GCC High tenants.

**Methods:**
- `isAvailable(): bool` — returns true if `pwsh` binary exists AND certificate is configured
- `getLabels(): array` — runs `Get-Label -IncludeDetailedLabelActions | ConvertTo-Json -Depth 5`, parses JSON
- `getPolicies(): array` — runs `Get-LabelPolicy | ConvertTo-Json -Depth 5`, parses JSON

**Auth:** Certificate-based app-only via `Connect-IPPSSession`:
```
-AppId {client_id}
-Certificate {cert_path}
-Organization {tenant}.onmicrosoft.us
-ConnectionUri https://ps.compliance.protection.office365.us/powershell-liveid/
-AzureADAuthorizationEndpointUri https://login.microsoftonline.us/organizations
```

**Execution:** Uses Laravel's `Process` facade with 60-second timeout. Runs `pwsh -NoProfile -NonInteractive -Command {script}`.

**Label parsing:** Maps PowerShell output to same schema as Graph labels:
- `ImmutableId` → `label_id`
- `DisplayName` → `name`
- `Color` → `color` (hex triplet)
- `Tooltip` → `tooltip`
- `Comment` → `description`
- `Priority` → `priority`
- `ParentId` → parent lookup
- `EncryptionEnabled` → `protection_type`
- `ContentType` → `scope`

**Config:**
```php
// config/graph.php
'compliance_certificate_path' => env('COMPLIANCE_CERTIFICATE_PATH'),
'compliance_certificate_password' => env('COMPLIANCE_CERTIFICATE_PASSWORD'),
```

### 3. Updated SensitivityLabelService

**File:** `app/Services/SensitivityLabelService.php`

**Constructor:** Add `CompliancePowerShellService $compliance`

**`syncLabels()` — three-tier dispatch:**
```
try Graph API (Tier 1)
catch → if $compliance->isAvailable() → try PowerShell (Tier 2)
         else → $labelFetchFailed = true (Tier 3 stubs created during syncSiteLabels)

if Tier 1 or 2 succeeded → delete stale labels
if Tier 3 → skip stale cleanup
```

**`syncPolicies()` — two-tier:**
```
try Graph API (Tier 1)
catch → if $compliance->isAvailable() → try PowerShell (Tier 2)
         else → skip, return 0
```

**`syncSiteLabels()` — new label discovery:**
```
1. Fetch sites from Graph (/sites?search=*)
2. Fetch enriched sharing map from SPO Admin API
3. Fetch group-to-label map:
   GET /groups?$filter=groupTypes/any(g:g eq 'Unified')&$select=id,displayName,assignedLabels
   For each group with labels: GET /groups/{groupId}/sites/root?$select=id,webUrl
   Build siteId → { labelId, labelName } map
4. For each site:
   a. Look up label from group map
   b. If not found → try SPO REST /_api/site/SensitivityLabelInfo
   c. If not found → try existing per-site Graph call (last resort)
   d. If label found but not in DB → auto-create stub (Tier 3)
   e. Look up sharing/access data from enriched SPO Admin map
   f. Upsert SiteSensitivityLabel record
```

**`fetchSharingCapabilities()` → `fetchSiteProperties()`:** Renamed to reflect richer data. Returns full enriched map.

### 4. Updated SharePointSiteService

**File:** `app/Services/SharePointSiteService.php`

**`syncSites()`:** Consume enriched site properties map. Store all new fields in `sharepoint_sites` records.

**`syncSiteExternalUsers()` (new):**
```
For each SharePoint site in database:
  GET /sites/{siteId}/lists/User Information List/items?$expand=fields&$top=999
  Filter: #EXT# in fields/UserName or fields/Name
  Extract email from login name format:
    i:0#.f|membership|john_contoso.com#EXT#@tenant.onmicrosoft.us → john@contoso.com
  Match against GuestUser records by email
  Upsert SharePointSitePermission with granted_via = 'site_access'
  Handle pagination via @odata.nextLink (cap at 5000 per site)
```

**Error handling:** 404 → skip silently. 403 → log warning. Other → log, continue.

**Gated by config:** `config('graph.sync_site_users', true)`

### 5. Database Migration

**New migration:** `add_access_controls_to_sharepoint_sites`

```php
Schema::table('sharepoint_sites', function (Blueprint $table) {
    // Sharing controls
    $table->string('sharing_domain_restriction_mode')->nullable();
    $table->text('sharing_allowed_domain_list')->nullable();
    $table->text('sharing_blocked_domain_list')->nullable();
    $table->string('default_sharing_link_type')->nullable();
    $table->string('default_link_permission')->nullable();

    // External user expiration
    $table->integer('external_user_expiration_days')->nullable();
    $table->boolean('override_tenant_expiration_policy')->default(false);

    // Access restrictions
    $table->string('conditional_access_policy')->nullable();
    $table->boolean('allow_editing')->default(true);
    $table->string('limited_access_file_type')->nullable();
    $table->boolean('allow_downloading_non_web_viewable')->default(true);
});
```

No changes to `sensitivity_labels`, `site_sensitivity_labels`, or `sharepoint_site_permissions` tables. New `granted_via` value `'site_access'` uses existing column.

### 6. Config Additions

**File:** `config/graph.php`
```php
'compliance_certificate_path' => env('COMPLIANCE_CERTIFICATE_PATH'),
'compliance_certificate_password' => env('COMPLIANCE_CERTIFICATE_PASSWORD'),
'sync_site_users' => env('MICROSOFT_GRAPH_SYNC_SITE_USERS', true),
```

### 7. Docker Changes

**File:** `Dockerfile`
```dockerfile
RUN apt-get update && apt-get install -y powershell \
    && pwsh -Command "Install-Module ExchangeOnlineManagement -Force -Scope AllUsers"
```

### 8. Command Updates

**`SyncSensitivityLabels`:** Report which tier was used:
```
Labels synced: 12 (via PowerShell)
Policies synced: 3 (via PowerShell)
Site labels synced: 45
Partner mappings rebuilt.
```

**`SyncSharePointSites`:** Add external user sync step:
```
Sites synced: 45
Permissions synced: 120
External users mapped: 87 (via User Information List)
```

### 9. Admin UI Updates

**Graph settings page (`Graph.vue`):**
- Add "Compliance Certificate Path" field (nullable, shown only when cloud_environment = gcc_high)
- Add "Compliance Certificate Password" field (password type, same conditional)

**Validation (`UpdateGraphSettingsRequest.php`):**
```php
'compliance_certificate_path' => ['nullable', 'string', 'max:500'],
'compliance_certificate_password' => ['nullable', 'string', 'max:255'],
```

### 10. Display UI Updates

**Sensitivity Labels Index (`Index.vue`):**
- Banner when labels have `protection_type = 'unknown'`: "Label details limited — full definitions unavailable in this cloud environment"
- Protection badge shows "Unknown" for stub labels

**SharePoint Sites Index/Show:**
- New columns: Conditional Access (badge), Allow Editing, Domain Restrictions, External User Expiration
- All read-only display fields

**Types (`sharepoint-site.ts`):**
```typescript
// New fields
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

---

## Data Flow

```
sync:sensitivity-labels
├── syncLabels()
│   ├── Tier 1: Graph API → full labels
│   ├── Tier 2: PowerShell Get-Label → full labels
│   └── Tier 3: (stubs created during syncSiteLabels)
├── syncPolicies()
│   ├── Tier 1: Graph API → full policies
│   ├── Tier 2: PowerShell Get-LabelPolicy → full policies
│   └── Skip if unavailable
├── syncSiteLabels()
│   ├── Graph /sites → site list
│   ├── SPO Admin API → enriched site properties (sharing + access controls)
│   ├── Graph /groups → group-to-label map
│   ├── SPO REST /_api/site/SensitivityLabelInfo → non-group site labels
│   └── Auto-create label stubs if needed (Tier 3)
└── buildPartnerMappings()

sync:sharepoint-sites
├── syncSites()
│   ├── Graph /sites → site list
│   └── SPO Admin API → enriched site properties
├── syncPermissions()
│   └── Graph /sites/{id}/permissions → app-level permissions
└── syncSiteExternalUsers()  [NEW]
    └── Graph /sites/{id}/lists/User Information List/items → external users per site
```

---

## Files Changed

| File | Type | Change |
|------|------|--------|
| `app/Services/SharePointAdminService.php` | Modified | Enrich return data with all sharing/access fields |
| `app/Services/CompliancePowerShellService.php` | **New** | PowerShell Get-Label/Get-LabelPolicy wrapper |
| `app/Services/SensitivityLabelService.php` | Modified | Three-tier label sync, Groups API, SPO REST, stub creation |
| `app/Services/SharePointSiteService.php` | Modified | Consume enriched data, new syncSiteExternalUsers() |
| `config/graph.php` | Modified | Add certificate + sync_site_users config |
| `.env.example` | Modified | Add env vars |
| `database/migrations/..._add_access_controls_to_sharepoint_sites.php` | **New** | Add columns |
| `app/Console/Commands/SyncSensitivityLabels.php` | Modified | Tier reporting |
| `app/Console/Commands/SyncSharePointSites.php` | Modified | Add external user sync |
| `app/Http/Controllers/Admin/AdminGraphController.php` | Modified | Certificate settings |
| `app/Http/Requests/UpdateGraphSettingsRequest.php` | Modified | Certificate validation |
| `resources/js/pages/admin/Graph.vue` | Modified | Certificate fields (GCC High conditional) |
| `resources/js/pages/sensitivity-labels/Index.vue` | Modified | Stub banner |
| `resources/js/pages/sharepoint/Index.vue` | Modified | Access control columns |
| `resources/js/pages/sharepoint/Show.vue` | Modified | Access control fields |
| `resources/js/types/sharepoint-site.ts` | Modified | New field types |
| `Dockerfile` | Modified | Add pwsh + ExchangeOnlineManagement |
| `tests/Feature/Services/SharePointSiteServiceTest.php` | Modified | Enriched data mocks, User Info List tests |
| `tests/Feature/Services/SensitivityLabelServiceTest.php` | Modified | Three-tier tests, stub tests |
| `tests/Feature/Services/CompliancePowerShellServiceTest.php` | **New** | PowerShell parsing tests |

## Verification

1. `php artisan test --filter=SharePointSiteServiceTest` — all pass
2. `php artisan test --filter=SensitivityLabelServiceTest` — all pass
3. `php artisan test --filter=CompliancePowerShellServiceTest` — all pass
4. `php artisan sync:sensitivity-labels` — reports tier used, syncs labels
5. `php artisan sync:sharepoint-sites` — syncs sites with enriched data + external users
6. GCC High: labels sync via PowerShell (Tier 2) or stubs (Tier 3)
7. Commercial: no behavior change (Tier 1 Graph API)
8. UI shows access control data on SharePoint pages
9. UI shows stub banner on sensitivity labels when applicable
