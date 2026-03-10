# Plan: GCC High Sensitivity Label Sync via SharePoint Admin API

## Context

The `sync:sensitivity-labels` command fails in GCC High with `Resource not found for the segment 'informationProtection'`. Research confirms that **both** Graph API sensitivity label endpoints are ❌ for US Government L4 (GCC High):

- `GET /security/informationProtection/sensitivityLabels` (beta) — ❌ GCC High
- `GET /security/dataSecurityAndGovernance/sensitivityLabels` (v1.0) — ❌ GCC High
- `GET /security/informationProtection/sensitivityLabels/policies` — ❌ GCC High
- `GET /sites/{siteId}/sensitivityLabel` — also not available in GCC High

**Solution:** The SPO Admin API's `GetSitePropertiesFromSharePointByFilters` (which already works in GCC High for sharing capabilities) also returns `SensitivityLabel` (GUID) and `SensitivityLabel2` (display name) per site. We can use this to populate site-to-label mappings without needing the Graph label definitions endpoint.

**Strategy:** Make label sync **graceful** — if the Graph `informationProtection` endpoints fail (GCC High), skip `syncLabels()` and `syncPolicies()`, but still sync site labels using SPO Admin API data. Labels will be auto-created from the SPO Admin API data (GUID + display name) when the Graph endpoint is unavailable.

---

## Step 1: Expand `SharePointAdminService.getSiteProperties()` return data

**File:** `app/Services/SharePointAdminService.php`

Currently returns `array<normalizedUrl, sharingCapability>`. Change to return richer data including sensitivity label info:

```php
// Change return type from simple sharing map to full site property data
// Each entry: ['sharingCapability' => string, 'sensitivityLabelId' => ?string, 'sensitivityLabelName' => ?string]
```

Update `getSiteProperties()` to also extract:
- `$item['SensitivityLabel']` → GUID string (the label ID)
- `$item['SensitivityLabel2']` → string (the display name)

Return format changes to:
```php
$results[$normalizedUrl] = [
    'sharingCapability' => $sharingCapability,
    'sensitivityLabelId' => $item['SensitivityLabel'] ?? null,
    'sensitivityLabelName' => $item['SensitivityLabel2'] ?? null,
];
```

Filter out empty/zero GUIDs (`00000000-0000-0000-0000-000000000000`).

## Step 2: Update consumers of `getSiteProperties()`

**File:** `app/Services/SharePointSiteService.php`

Update `syncSites()` to use new return format:
```php
// Before: $sharingMap[$siteUrl] ?? 'Disabled'
// After:  $sharingMap[$siteUrl]['sharingCapability'] ?? 'Disabled'
```

Also update `fetchSharingCapabilities()` — no signature change needed, just internal data shape.

Also look up the sensitivity label from the SPO Admin data when the per-site Graph call (`/sites/{siteId}/sensitivityLabel`) fails. This provides a fallback:
```php
$labelId = $this->fetchSiteLabelFromGraph($graphSite['id']);
if (! $labelId) {
    $labelId = $spoData['sensitivityLabelId'] ?? null;
}
```

**File:** `app/Services/SensitivityLabelService.php`

Same update for `syncSiteLabels()` — use `$sharingMap[$siteUrl]['sharingCapability']` and also use `$sharingMap[$siteUrl]['sensitivityLabelId']` as fallback when per-site Graph label fetch fails.

## Step 3: Make `syncLabels()` and `syncPolicies()` graceful on GCC High

**File:** `app/Services/SensitivityLabelService.php`

Wrap `fetchLabelsFromGraph()` in try/catch. On failure, log a warning and return empty array (don't throw). Same for `fetchLabelPoliciesFromGraph()`.

This means `syncLabels()` returns `['labels_synced' => 0]` on GCC High (no labels deleted either — skip the delete step when fetch returns empty due to error).

Add a `private bool $labelFetchFailed = false` flag so `syncLabels()` can communicate to callers that it couldn't fetch (vs. tenant genuinely having zero labels). When this flag is true, skip the stale-label cleanup.

## Step 4: Auto-create labels from SPO Admin API data in `syncSiteLabels()`

**File:** `app/Services/SensitivityLabelService.php`

In `syncSiteLabels()`, when using SPO Admin API label data (fallback path), auto-create `SensitivityLabel` records if they don't exist:

```php
// If label doesn't exist in DB, create a stub from SPO Admin data
if (! $label && $spoLabelId) {
    $label = SensitivityLabel::updateOrCreate(
        ['label_id' => $spoLabelId],
        [
            'name' => $spoLabelName ?? 'Unknown Label',
            'protection_type' => 'unknown',
            'synced_at' => now(),
        ]
    );
}
```

This ensures site-to-label mappings work even without the Graph label definitions endpoint.

## Step 5: Update sync command for graceful handling

**File:** `app/Console/Commands/SyncSensitivityLabels.php`

Update output messages to indicate when running in degraded mode (GCC High / Graph endpoint unavailable). The command should still succeed (return SUCCESS) even if label definitions can't be fetched, as long as site labels sync.

## Step 6: Update tests

**File:** `tests/Feature/Services/SensitivityLabelServiceTest.php`

- Add `'graph.cloud_environment' => 'commercial'` and `'graph.sharepoint_tenant' => 'contoso'` to `beforeEach` config
- Add `Cache::forget('spo_admin_access_token')` to `beforeEach`
- Add SPO admin API mock to `fakeGraphForSensitivityLabels()` function
- Add test: `syncLabels gracefully handles unavailable Graph endpoint`
- Add test: `syncSiteLabels auto-creates labels from SPO Admin API data`

**File:** `tests/Feature/Services/SharePointSiteServiceTest.php`

- Update `fakeGraphForSharePoint()` and tests to use new `getSiteProperties()` return format (dict with `sharingCapability` key instead of plain string)

---

## Files Changed

| File | Change |
|------|--------|
| `app/Services/SharePointAdminService.php` | Return richer data (sharing + label GUID + label name) |
| `app/Services/SharePointSiteService.php` | Adapt to new return format, use SPO label as fallback |
| `app/Services/SensitivityLabelService.php` | Graceful Graph failure, auto-create labels from SPO data |
| `app/Console/Commands/SyncSensitivityLabels.php` | Degraded mode output messages |
| `tests/Feature/Services/SharePointSiteServiceTest.php` | Update for new data format |
| `tests/Feature/Services/SensitivityLabelServiceTest.php` | Add GCC High / graceful failure tests |

## Verification

1. `php artisan test --filter=SharePointSiteServiceTest` — all tests pass
2. `php artisan test --filter=SensitivityLabelServiceTest` — all tests pass
3. `php artisan sync:sensitivity-labels` — succeeds in GCC High (graceful degradation for label definitions, site labels from SPO Admin API)
4. Check sensitivity labels page in UI — shows labels auto-created from SPO data
5. `php artisan sync:sharepoint-sites` — still works correctly
