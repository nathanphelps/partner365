# Plan: SharePoint Admin API for Sharing Capability in GCC High

## Context

The Graph API `/sites` endpoint does not support `sharingCapability` in `$select` for GCC High — it returns a 400 error. This breaks `sync:sharepoint-sites` and `sync:sensitivity-labels`. The `sharingCapability` is a SharePoint tenant admin property, not a Graph site property. The fix is to source it from the **SharePoint Admin REST API** instead, which works in both commercial and GCC High.

Additionally, `.env.example` is missing several GovCloud-related vars (`CLOUD_ENVIRONMENT`, `BASE_URL`) that we discovered during setup.

---

## Step 1: Add `sharePointAdminUrl()` to CloudEnvironment enum

**File:** `app/Enums/CloudEnvironment.php`

Add after `defaultScopes()`:

```php
public function sharePointAdminUrl(string $tenantSlug): string
{
    return match ($this) {
        self::Commercial => "https://{$tenantSlug}-admin.sharepoint.com",
        self::GccHigh => "https://{$tenantSlug}-admin.sharepoint.us",
    };
}
```

## Step 2: Add `sharepoint_tenant` to config and .env.example

**File:** `config/graph.php` — add:
```php
'sharepoint_tenant' => env('MICROSOFT_GRAPH_SHAREPOINT_TENANT'),
```

**File:** `.env.example` — add after existing `MICROSOFT_GRAPH_SCOPES`:
```env
MICROSOFT_GRAPH_CLOUD_ENVIRONMENT=commercial
MICROSOFT_GRAPH_BASE_URL="https://graph.microsoft.com/v1.0"
MICROSOFT_GRAPH_SHAREPOINT_TENANT=
```

## Step 3: Create `SharePointAdminService`

**File:** `app/Services/SharePointAdminService.php` (new)

Follows `MicrosoftGraphService` patterns (Setting::get with config fallback, Cache::remember for token, GraphApiException on failure).

**Key methods:**
- `isConfigured(): bool` — returns true if sharepoint_tenant slug is set
- `getAccessToken(): string` — cached as `spo_admin_access_token`, scoped to `{adminUrl}/.default` (NOT Graph scope)
- `getSiteProperties(): array` — calls `POST /_api/SPO.Tenant/GetSitePropertiesFromSharePointByFilters`, handles pagination via `_nextStartIndex`, returns associative array keyed by normalized URL → sharing capability string

**Sharing capability mapping:**
```php
private const SHARING_MAP = [
    0 => 'Disabled',
    1 => 'ExternalUserSharingOnly',
    2 => 'ExternalUserAndGuestSharing',
    3 => 'ExistingExternalUserSharingOnly',
];
```

## Step 4: Update `SharePointSiteService`

**File:** `app/Services/SharePointSiteService.php`

- Add `SharePointAdminService $spoAdmin` to constructor
- `fetchSitesFromGraph()`: remove `sharingCapability` from `$select`
- `syncSites()`: fetch sharing map via `$spoAdmin->getSiteProperties()`, look up each site's URL in the map. Graceful fallback to `'Disabled'` if unavailable.
- Add `fetchSharingCapabilities(): array` private helper with try/catch + Log::warning

## Step 5: Update `SensitivityLabelService`

**File:** `app/Services/SensitivityLabelService.php`

- Add `SharePointAdminService $spoAdmin` to constructor
- `fetchSitesFromGraph()`: remove `sharingCapability` from `$select`
- `syncSiteLabels()`: fetch sharing map, look up by normalized URL
- Add same `fetchSharingCapabilities()` private helper

## Step 6: Update admin settings UI

**File:** `app/Http/Requests/UpdateGraphSettingsRequest.php` — add rule:
```php
'sharepoint_tenant' => ['nullable', 'string', 'max:63', 'regex:/^[a-zA-Z0-9-]+$/'],
```

**File:** `app/Http/Controllers/Admin/AdminGraphController.php`:
- `edit()`: add `'sharepoint_tenant'` to settings array
- `update()`: add `Setting::set('graph', 'sharepoint_tenant', ...)` and `Cache::forget('spo_admin_access_token')`

**File:** `resources/js/pages/admin/Graph.vue`:
- Add `sharepoint_tenant` to props type, form data, and template (field label: "SharePoint Tenant Slug", placeholder: "contoso")

## Step 7: Update tests

**File:** `tests/Feature/Services/SharePointSiteServiceTest.php`

- Add `config(['graph.sharepoint_tenant' => 'contoso'])` to `beforeEach`
- Add `Cache::forget('spo_admin_access_token')` to `beforeEach`
- Add SPO admin API mock to `fakeGraphForSharePoint()`
- Remove `'sharingCapability'` from `makeGraphSite()` defaults
- Add test: `syncSites defaults to Disabled when SharePoint Admin API is unconfigured`

## Step 8: Update sync command description

**File:** `app/Console/Commands/SyncSharePointSites.php` — update `$description` to mention SharePoint Admin API.

---

## Files Changed

| File | Type | Change |
|------|------|--------|
| `app/Enums/CloudEnvironment.php` | Modified | Add `sharePointAdminUrl()` |
| `config/graph.php` | Modified | Add `sharepoint_tenant` |
| `.env.example` | Modified | Add 3 env vars |
| `app/Services/SharePointAdminService.php` | **New** | SPO admin REST API client |
| `app/Services/SharePointSiteService.php` | Modified | Use admin API for sharing capability |
| `app/Services/SensitivityLabelService.php` | Modified | Use admin API for sharing capability |
| `app/Http/Requests/UpdateGraphSettingsRequest.php` | Modified | Add validation |
| `app/Http/Controllers/Admin/AdminGraphController.php` | Modified | Add settings read/write |
| `resources/js/pages/admin/Graph.vue` | Modified | Add UI field |
| `app/Console/Commands/SyncSharePointSites.php` | Modified | Update description |
| `tests/Feature/Services/SharePointSiteServiceTest.php` | Modified | Update mocks/assertions |

## Verification

1. Run `php artisan test --filter=SharePointSiteServiceTest` — all tests pass
2. Run `php artisan sync:sharepoint-sites` — syncs without `sharingCapability` 400 error
3. Run `php artisan sync:sensitivity-labels` — syncs without error
4. Check SharePoint sites in the UI show correct sharing capability values
