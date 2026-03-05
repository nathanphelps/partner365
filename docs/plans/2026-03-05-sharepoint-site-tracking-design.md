# SharePoint Site Tracking & Partner Exposure

**Date:** 2026-03-05
**Status:** Approved

## Goal

Add a dedicated SharePoint Sites section and per-partner SharePoint exposure view, showing which sites external partners can access through their guest users.

## Approach

Dedicated `SharePointSite` model + separate `SharePointSitePermission` model (Approach B). Follows the established pattern used by conditional access policies and sensitivity labels: model + service + sync command + controller + Vue pages.

Partner mapping is derived through guest users (no separate partner pivot). Site permissions are fetched via `GET /sites/{siteId}/permissions` to catch both direct grants and group membership.

## Data Model

### `sharepoint_sites` table

| Column | Type | Description |
|---|---|---|
| `id` | bigint PK | Auto-increment |
| `site_id` | string, unique | Graph API site ID |
| `display_name` | string | Site display name |
| `url` | string | Site web URL |
| `description` | string, nullable | Site description |
| `sensitivity_label_id` | FK, nullable | Links to `sensitivity_labels` |
| `external_sharing_capability` | string | e.g. `Disabled`, `ExternalUserSharingOnly` |
| `storage_used_bytes` | bigint, nullable | Storage consumed |
| `last_activity_at` | datetime, nullable | Last activity on site |
| `owner_display_name` | string, nullable | Site owner name |
| `owner_email` | string, nullable | Site owner email |
| `member_count` | int, nullable | Total member count |
| `raw_json` | json, nullable | Full Graph API response |
| `synced_at` | datetime | Last sync timestamp |
| `timestamps` | | created_at / updated_at |

### `sharepoint_site_permissions` table

| Column | Type | Description |
|---|---|---|
| `id` | bigint PK | |
| `sharepoint_site_id` | FK | Links to `sharepoint_sites` |
| `guest_user_id` | FK | Links to `guest_users` |
| `role` | string | Permission role (read, write, owner, etc.) |
| `granted_via` | string | `direct`, `sharing_link`, `group_membership` |
| `timestamps` | | |

No separate partner pivot needed -- partner mapping derived through `guest_users.partner_organization_id`.

## Backend

### `SharePointSiteService`

- `syncSites()` -- Fetch all sites via `GET /sites?search=*`, upsert into `sharepoint_sites`. Link sensitivity labels via `GET /sites/{siteId}/sensitivityLabel`.
- `syncPermissions()` -- For each site with external sharing enabled, call `GET /sites/{siteId}/permissions`. Match grantees against known `guest_users` by email/user ID. Upsert into `sharepoint_site_permissions`.
- `getPartnerExposure(PartnerOrganization $partner)` -- Query sites accessible by a partner's guest users through `sharepoint_site_permissions` -> `guest_users` -> `partner_organization_id`.

### `SyncSharePointSites` Artisan command (`sync:sharepoint-sites`)

- Orchestrates `syncSites()` then `syncPermissions()`
- Creates `SyncLog` entry, logs `ActivityAction::SharePointSitesSynced`
- Runs on 15-minute schedule alongside existing sync commands

### `SharePointSiteController` (read-only)

- `index()` -- Paginated site list with permission counts, uncovered partner count, filterable by sharing capability
- `show(SharePointSite $site)` -- Site detail with guest users grouped by partner

### Partner show page

`PartnerOrganizationController::show()` adds `sharePointSites` prop queried through guest user -> permission join.

### New enum value

`ActivityAction::SharePointSitesSynced`

## Frontend

### New pages

- `resources/js/pages/sharepoint-sites/Index.vue` -- Paginated table: site name (linked), URL, sensitivity label badge, sharing capability badge, guest user count, partner count. Filter by sharing capability. "Uncovered partners" warning banner.
- `resources/js/pages/sharepoint-sites/Show.vue` -- Site detail header (name, URL, owner, storage, last activity, sensitivity label, sharing capability). Table of guest users with access: name, email, partner (linked), role, granted via. Filterable by partner.

### Partner show page addition

New "SharePoint Sites" card on `partners/Show.vue` matching existing Conditional Access / Sensitivity Labels card pattern:
- Header: HardDrive icon + "SharePoint Sites (count)"
- Empty state: yellow warning banner
- Table: Site Name (linked), Sharing Capability, Role, Sensitivity Label badge

### New TypeScript type (`resources/js/types/sharepoint.ts`)

```typescript
interface SharePointSite {
  id: number;
  site_id: string;
  display_name: string;
  url: string;
  external_sharing_capability: string;
  sensitivity_label?: SensitivityLabel;
  owner_display_name?: string;
  storage_used_bytes?: number;
  last_activity_at?: string;
  member_count?: number;
  guest_permissions_count?: number;
  partners_count?: number;
}
```

### Routes

`GET /sharepoint-sites` and `GET /sharepoint-sites/{sharePointSite}`, added to nav sidebar.

## Testing

- `Services/SharePointSiteServiceTest.php` -- Mock Graph API for site listing, permission enumeration, sensitivity label fetching. Test sync upsert/delete, permission matching, partner exposure queries.
- `Controllers/SharePointSiteControllerTest.php` -- Test index pagination/filtering, show page with permissions, partner show page includes SharePoint data.
- `Commands/SyncSharePointSitesTest.php` -- Test command orchestration, SyncLog, activity logging.

Graph API mocking follows existing pattern with `Http::fake()`.
