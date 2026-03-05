# Admin Panel Design

## Problem

System configuration (Graph credentials, sync intervals) and user management require server access. Admins need a self-service admin panel to manage these without redeployment.

## Solution

A `/admin` section with three pages: Microsoft Graph configuration, User Management (with approval workflow), and Sync Settings. All admin-only, database-backed, with `.env` fallbacks where applicable.

---

## 1. Shared Data Layer

### `settings` table

| Column    | Type           | Notes                              |
|-----------|----------------|------------------------------------|
| id        | ulid           | Primary key                        |
| group     | string         | e.g. `graph`, `sync`              |
| key       | string         | e.g. `tenant_id`, `client_secret` |
| value     | text, nullable | Stored value                       |
| encrypted | boolean        | Whether value is encrypted at rest |
| timestamps |               |                                    |

Unique constraint on `(group, key)`.

### `Setting` model

- Auto-encrypts/decrypts values where `encrypted = true`.
- `Setting::get('graph', 'tenant_id', fallback)` -- returns DB value or falls back to config value.
- `Setting::set('graph', 'tenant_id', value)` -- upserts the setting.

### `sync_logs` table

| Column         | Type               | Notes                          |
|----------------|--------------------|--------------------------------|
| id             | ulid               | Primary key                    |
| type           | string             | `partners` or `guests`         |
| status         | string             | `running`, `completed`, `failed` |
| records_synced | integer, nullable  | Count of records processed     |
| error_message  | text, nullable     | On failure                     |
| started_at     | timestamp          |                                |
| completed_at   | timestamp, nullable |                               |

### `SyncLog` model

Basic Eloquent model with `scopeRecent(int $limit)` and `scopeByType(string $type)`.

### `users` table changes (new migration)

- Add `approved_at` column (timestamp, nullable) -- null means pending approval.
- Add `approved_by` column (foreign key to users, nullable).
- Existing users backfilled with `approved_at = now()`.

### `User` model additions

- `isApproved()` -- checks `approved_at !== null`.
- `approve(User $approver)` -- sets `approved_at` and `approved_by`.
- `scopePending()` -- where `approved_at` is null.

### Activity logging additions

Add to `ActivityAction` enum: `settings_updated`, `user_approved`, `user_role_changed`, `user_deleted`, `sync_triggered`.

---

## 2. Shared Infrastructure

### AdminLayout.vue

Extends existing app layout with sidebar navigation:
- Microsoft Graph
- User Management
- Sync Settings

### EnsureApproved middleware

- Checks `auth()->user()->isApproved()`.
- If not approved, renders `PendingApproval` Inertia page.
- Runs after `auth`, applied to all protected routes.
- Admin routes are not exempt, but first registered user is auto-approved.

### Navigation

Add "Admin" link to main app nav, visible only to admin role users.

### Routes (all `role:admin`)

```
GET    /admin                       → redirect to /admin/graph
GET    /admin/graph                 → AdminGraphController@edit
PUT    /admin/graph                 → AdminGraphController@update
POST   /admin/graph/test            → AdminGraphController@testConnection
GET    /admin/users                 → AdminUserController@index
PATCH  /admin/users/{user}/role     → AdminUserController@updateRole
POST   /admin/users/{user}/approve  → AdminUserController@approve
DELETE /admin/users/{user}          → AdminUserController@destroy
GET    /admin/sync                  → AdminSyncController@edit
PUT    /admin/sync                  → AdminSyncController@update
POST   /admin/sync/{type}/run       → AdminSyncController@run
```

---

## 3. Microsoft Graph Configuration

### MicrosoftGraphService changes

Replace `config('graph.*')` calls with `Setting::get('graph', ...)`, falling back to `.env` values. Clear cached Graph token when credentials change.

### AdminGraphController

- `edit()` -- Loads graph settings, masks client secret (last 4 chars), renders Inertia page.
- `update(UpdateGraphSettingsRequest)` -- Validates, saves, clears cached Graph token, logs activity.
- `testConnection()` -- Attempts token acquisition against `login.microsoftonline.com`, returns success/failure.

### UpdateGraphSettingsRequest

- Authorization: `isAdmin()`
- Rules: `tenant_id` required uuid, `client_id` required uuid, `client_secret` nullable (only update if provided), `scopes` required string, `base_url` required url, `sync_interval_minutes` required integer min:1 max:1440.

### `pages/admin/Graph.vue`

- Form fields for all 6 config values.
- Client secret: masked display when set, empty password input to replace, "Leave blank to keep current" hint.
- "Test Connection" button: POST to `/admin/graph/test`, inline success/error feedback.
- "Save" button: standard Inertia form submission with success toast.
- Inline validation errors.

---

## 4. User Management

### AdminUserController

- `index()` -- List all users with role, status (pending/active), `approved_at`, paginated. Pending users sorted first.
- `updateRole(request, user)` -- Change a user's role. Cannot demote yourself.
- `approve(user)` -- Sets `approved_at` and `approved_by`.
- `destroy(user)` -- Delete the user. Cannot delete yourself.

### `pages/admin/Users.vue`

- Table: Name, Email, Role, Status (pending/active badge), Approved At, Actions.
- Pending users shown with yellow badge and "Approve" button.
- Role column: dropdown select (admin/operator/viewer) that auto-saves on change.
- Delete button with confirmation dialog. Disabled on current user's row.
- No create/invite -- users self-register via Fortify, admins approve.

### `pages/PendingApproval.vue`

- Centered card: "Your account is pending approval" message.
- Shows user's name/email.
- Logout button.
- Standalone page (no nav/sidebar), styled like auth pages.

---

## 5. Sync Settings

### Sync command changes

- `sync:partners` and `sync:guests` create a `SyncLog` at start, update on completion/failure.
- Read interval from `Setting::get('sync', ...)` with fallback to `config('graph.sync_interval_minutes')`.
- Scheduler reads intervals dynamically instead of hardcoded `everyFifteenMinutes()`.

### AdminSyncController

- `edit()` -- Returns current intervals for both sync types, plus last 10 `SyncLog` entries per type.
- `update(UpdateSyncSettingsRequest)` -- Saves interval values, logs activity.
- `run(string $type)` -- Dispatches sync command as queued job, returns immediately with "Sync started".

### UpdateSyncSettingsRequest

Both intervals required, integer, min:1 max:1440.

### `pages/admin/Sync.vue`

- Two sections (Partners / Guests), each with:
  - Interval input (minutes) with save.
  - "Sync Now" button with spinner, refreshes log on completion.
  - Recent sync history table (last 10): status badge, records synced, duration, error message (expandable), timestamp.

---

## 6. Testing

### Setting model tests
- `get()` with fallback, `set()`, encryption round-trip.

### Graph config tests
- `AdminGraphController`: edit returns masked secret, update saves values, blank secret preserves existing, testConnection success/failure.
- `MicrosoftGraphService`: reads from `Setting::get()`, falls back to config.

### User management tests
- `EnsureApproved`: unapproved user sees pending page, approved user passes through.
- `AdminUserController`: index lists users (pending first), approve sets timestamps, role change works, cannot demote self, cannot delete self.
- First registered user is auto-approved.

### Sync settings tests
- `AdminSyncController`: edit returns intervals and logs, update saves intervals, run dispatches job.
- `SyncLog`: created/updated by sync commands, `scopeRecent` returns correct count.
- Sync commands: read interval from settings, fall back to config, create log entries.

### Authorization
- Non-admin and operator users get 403 on all `/admin/*` routes.

### Patterns
- `Http::fake()`, in-memory SQLite, Pest PHP.
