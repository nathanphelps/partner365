# Admin Panel: Microsoft Graph Configuration

## Problem

Microsoft Graph credentials (tenant ID, client ID, client secret) and operational settings (scopes, base URL, sync interval) are currently stored in `.env` and read via `config/graph.php`. Admins cannot change these without server access and redeployment.

## Solution

Move Graph configuration to a database-backed admin panel at `/admin/graph`, with encrypted secret storage, a test connection feature, and fallback to `.env` values.

## Data Layer

### `settings` table

| Column      | Type           | Notes                              |
|-------------|----------------|------------------------------------|
| id          | ulid           | Primary key                        |
| group       | string         | e.g. `graph`, `sync`              |
| key         | string         | e.g. `tenant_id`, `client_secret` |
| value       | text, nullable | Stored value                       |
| encrypted   | boolean        | Whether value is encrypted at rest |
| timestamps  |                |                                    |

Unique constraint on `(group, key)`.

### `Setting` model

- Auto-encrypts/decrypts values where `encrypted = true`.
- `Setting::get('graph', 'tenant_id', fallback)` -- returns DB value or falls back to config value.
- `Setting::set('graph', 'tenant_id', value)` -- upserts the setting.

### `MicrosoftGraphService` changes

Replace `config('graph.*')` calls with `Setting::get('graph', ...)`, falling back to `.env` values. Clear cached Graph token when credentials change.

## Admin Backend

### Routes (all `role:admin`)

```
GET    /admin                → redirect to /admin/graph
GET    /admin/graph          → AdminGraphController@edit
PUT    /admin/graph          → AdminGraphController@update
POST   /admin/graph/test     → AdminGraphController@testConnection
GET    /admin/users          → (future)
GET    /admin/sync           → (future)
```

### AdminGraphController

- `edit()` -- Loads graph settings, masks client secret (last 4 chars), renders Inertia page.
- `update(UpdateGraphSettingsRequest)` -- Validates, saves, clears cached Graph token, logs activity.
- `testConnection()` -- Attempts token acquisition, returns success/failure.

### UpdateGraphSettingsRequest

- Authorization: `isAdmin()`
- Rules: `tenant_id` required uuid, `client_id` required uuid, `client_secret` nullable (only update if provided), `scopes` required string, `base_url` required url, `sync_interval_minutes` required integer min:1 max:1440

### Activity logging

Add `settings_updated` to `ActivityAction` enum.

## Admin Frontend

### AdminLayout.vue

Extends existing app layout with sidebar navigation:
- Microsoft Graph (active)
- User Management (coming soon, disabled)
- Sync Settings (coming soon, disabled)

### `pages/admin/Graph.vue`

- Form fields for all 6 config values.
- Client secret: masked display when set, empty password input to replace, "Leave blank to keep current" hint.
- "Test Connection" button: POST to `/admin/graph/test`, inline success/error feedback.
- "Save" button: standard Inertia form submission with success toast.
- Inline validation errors.

### Navigation

Add "Admin" link to main app nav, visible only to admin role users.

## Testing

- `Setting` model: get with fallback, set, encryption round-trip.
- `AdminGraphController`: edit returns masked secret, update saves values, blank secret preserves existing, testConnection success/failure.
- Authorization: non-admin users get 403 on `/admin/*` routes.
- `MicrosoftGraphService`: reads from `Setting::get()`, falls back to config.
- Patterns: `Http::fake()`, in-memory SQLite, Pest PHP.
