# Guest User Access Visibility Design

## Overview

Add read-only visibility into what each guest user has access to: group memberships, app assignments, Teams memberships, and SharePoint sites. Data is live-fetched from Microsoft Graph API (not synced to local DB) and displayed in tabs on the guest detail page.

## Backend

### New GuestUserService Methods

- `getUserGroups(string $entraUserId)` — `GET /users/{id}/memberOf`, filters to security groups, M365 groups, distribution lists
- `getUserApps(string $entraUserId)` — `GET /users/{id}/appRoleAssignments`
- `getUserTeams(string $entraUserId)` — `GET /users/{id}/joinedTeams`
- `getUserSites(string $entraUserId)` — gets M365 groups from `memberOf`, then `GET /groups/{groupId}/sites/root` for each

### New GuestUserController Endpoints

| Method | Route | Returns |
|--------|-------|---------|
| `groups(GuestUser $guest)` | `GET /guests/{guest}/groups` | JsonResponse |
| `apps(GuestUser $guest)` | `GET /guests/{guest}/apps` | JsonResponse |
| `teams(GuestUser $guest)` | `GET /guests/{guest}/teams` | JsonResponse |
| `sites(GuestUser $guest)` | `GET /guests/{guest}/sites` | JsonResponse |

All routes: `auth + verified + approved` middleware. Read-only, all roles can access.

Error handling: if Graph API fails, return 502 with error message.

### Caching

5-minute cache per user per data type. Key format: `guest_access:{entraId}:{type}`.

## Frontend

### Tab UI on Guest Show Page

5 tabs on `resources/js/pages/guests/Show.vue`:

1. **Overview** — existing content (default)
2. **Groups** — group memberships table
3. **Apps** — app assignments table
4. **Teams** — Teams memberships table
5. **Sites** — SharePoint sites table

Non-overview tabs lazy-load via axios on tab activation. Client-side cache so tab switching doesn't re-fetch.

### Table Columns

| Tab | Columns |
|-----|---------|
| Groups | Display Name, Type (Security/M365/Distribution), Description |
| Apps | App Display Name, Role, Assigned Date |
| Teams | Team Name, Description |
| Sites | Site Name, URL |

### States

- **Loading:** skeleton/spinner
- **Empty:** "No {type} found for this guest user."
- **Error:** "Unable to load {type}. Microsoft Graph API may be unavailable." + retry button

### TypeScript Types

Added to `resources/js/types/guest.ts`:

```typescript
type GuestGroup = {
    id: string
    displayName: string
    groupType: 'security' | 'microsoft365' | 'distribution'
    description: string | null
}

type GuestApp = {
    id: string
    appDisplayName: string
    roleName: string | null
    assignedAt: string | null
}

type GuestTeam = {
    id: string
    displayName: string
    description: string | null
}

type GuestSite = {
    id: string
    displayName: string
    webUrl: string
}
```

## Testing

Extend existing test files (no new test files):

**Service tests:** test each new method with Http::fake() — normal results, empty results, Graph API errors, sites resolution from M365 groups.

**Controller tests:** test JSON response structure, all roles can access, 404 for missing guest, 502 on Graph API failure.

## Non-Goals

- No database tables or migrations
- No sync commands
- No write operations on access data
- No SharePoint direct permission queries (derived from group memberships)
