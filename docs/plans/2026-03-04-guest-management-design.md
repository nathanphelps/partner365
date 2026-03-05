# Guest User Management by Partner Organization

## Goal

Improve guest user management by organizing guests by partner organization and adding richer management actions. Provide both per-partner and global cross-partner views with consistent UX.

## Backend

### New Routes

```
PATCH  /guests/{id}              → GuestUserController@update
POST   /guests/{id}/resend       → GuestUserController@resendInvitation
POST   /guests/bulk              → GuestUserController@bulkAction
GET    /partners/{id}/guests     → PartnerOrganizationController@guests
```

### Controller Changes

**GuestUserController** — new actions:

- `update($id)` — PATCH to toggle `accountEnabled`, update `displayName`
- `resendInvitation($id)` — POST to resend invite email via Graph API
- `bulkAction()` — POST accepting `{ action: 'disable'|'enable'|'delete'|'resend', ids: [...] }`. Returns `{ succeeded: [...], failed: [{ id, error }] }`

**PartnerOrganizationController** — new action:

- `guests($id)` — GET returning paginated guests for a specific partner

### Service Changes

**GuestUserService** — new methods:

- `enableUser($id)` — set `accountEnabled: true` via Graph API
- `disableUser($id)` — set `accountEnabled: false` via Graph API
- `resendInvitation($id)` — resend B2B invitation email via Graph API

Bulk operations iterate through IDs, calling Graph API per user, collecting successes and failures.

### Permissions

- Enable/disable, resend, edit, bulk actions: require `canManage()` (admin or operator)
- Delete: require `isAdmin()`
- Viewers: read-only access (see table, no action buttons)

### Activity Logging

All actions (enable, disable, resend, delete, edit, bulk) logged via `ActivityLogService` — one entry per affected user, not one per bulk batch.

## Frontend

### Shared GuestUserTable Component

**Location:** `resources/js/components/GuestUserTable.vue`

**Props:**

- `guests` — paginated data (Inertia pagination object)
- `partnerId?` — optional, scopes to a specific partner
- `canManage` — boolean, controls action button visibility

**Features:**

- Checkbox column for bulk selection (select all on page / individual)
- Columns: Name, Email, Status (badge), Last Sign-in, Account Enabled
- In global mode: additional Partner Org column with link to partner detail page
- Row actions dropdown: Edit, Enable/Disable, Resend Invite, Delete
- Bulk action bar (appears when items selected): Enable, Disable, Resend, Delete — all with confirmation modal
- Filters: status (pending/accepted/failed), account enabled (yes/no), search by name/email
- In global mode: additional partner filter dropdown
- Pagination

### Partner Detail Page (`partners/Show.vue`)

Add a "Guest Users" section below existing partner details. Embeds `GuestUserTable` with `partnerId` set.

### Global Guests Page (`guests/Index.vue`)

Replace current implementation with `GuestUserTable` in global mode. Add partner filter dropdown alongside existing filters.

### Edit Modal

Clicking "Edit" opens a lightweight modal/sheet for updating display name.

### Confirmations

Destructive actions (delete, disable) and all bulk actions show a confirmation dialog with count of affected users.

## Data Flow

- Frontend sends actions via Inertia forms or axios for bulk
- Backend calls Graph API, updates local DB on success
- No optimistic UI — wait for server response since Graph API calls can fail individually
- Bulk results return `{ succeeded, failed }`, frontend shows toast with summary
- Pagination uses Inertia — partner detail page loads guests as a separate prop for independent pagination
- Filters passed as query params, handled server-side
