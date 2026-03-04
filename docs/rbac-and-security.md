# RBAC & Security

## Role-Based Access Control

Partner365 uses a 3-tier role system stored in the `users.role` column as a string-backed PHP enum.

### Role Definitions

| Role | Value | Description |
|------|-------|-------------|
| **Admin** | `admin` | Full control over all resources, settings, and user management |
| **Operator** | `operator` | Create and manage partners and guests within established policies |
| **Viewer** | `viewer` | Read-only access to dashboards, partner lists, and activity logs |

### Permission Matrix

| Action | Admin | Operator | Viewer |
|--------|:-----:|:--------:|:------:|
| View dashboard | Yes | Yes | Yes |
| View partner list | Yes | Yes | Yes |
| View partner detail | Yes | Yes | Yes |
| Create partner | Yes | Yes | No |
| Update partner policy | Yes | Yes | No |
| Delete partner | Yes | No | No |
| View guest list | Yes | Yes | Yes |
| Invite guest | Yes | Yes | No |
| Delete guest | Yes | No | No |
| Manage templates | Yes | No | No |
| View activity log | Yes | Yes | Yes |

### UserRole Enum

```php
// app/Enums/UserRole.php
enum UserRole: string {
    case Admin    = 'admin';
    case Operator = 'operator';
    case Viewer   = 'viewer';

    public function canManage(): bool   // true for Admin + Operator
    public function isAdmin(): bool     // true for Admin only
}
```

## Enforcement Layers

### 1. Route Middleware

The `CheckRole` middleware protects entire route groups:

```php
// routes/web.php
Route::resource('templates', PartnerTemplateController::class)
    ->middleware('role:admin');
```

The middleware accepts one or more role names and returns 403 if the user's role doesn't match any:

```php
// app/Http/Middleware/CheckRole.php
public function handle($request, Closure $next, ...$roles): Response
{
    if (!in_array($request->user()->role->value, $roles)) {
        abort(403);
    }
    return $next($request);
}
```

### 2. Controller-Level Checks

Individual controller methods perform fine-grained authorization:

```php
// Only operators and admins can create
public function create()
{
    abort_unless(auth()->user()->role->canManage(), 403);
    // ...
}

// Only admins can delete
public function destroy(PartnerOrganization $partner)
{
    abort_unless(auth()->user()->role->isAdmin(), 403);
    // ...
}
```

### 3. Frontend Conditional Rendering

The user's role is shared via Inertia middleware and available in all Vue components:

```php
// HandleInertiaRequests.php
'auth' => [
    'user' => $request->user(),
    'role' => $request->user()?->role?->value,
],
```

Vue components conditionally render UI elements:

```vue
<Button v-if="$page.props.auth.role !== 'viewer'">
    Add Partner
</Button>
```

> **Important:** Frontend role checks are for UX only. All authorization is enforced server-side.

## Authentication

### Development

Laravel Fortify provides local email/password authentication with optional 2FA. This is the default configuration in the starter kit.

### Production

For production, configure Entra ID SSO via Laravel Socialite with the Microsoft provider. The Graph API itself uses a separate app-only client credentials flow (no user tokens are involved in API calls).

## Security Model

### Graph API Token Security

- Tokens are acquired via server-side client credentials flow
- Tokens are cached in Laravel's cache backend (never exposed to the browser)
- The cache key `msgraph_access_token` stores the token for 3500 seconds (just under 1-hour expiry)
- Client secrets are stored in `.env` and never committed to version control

### Input Validation

All user input is validated through dedicated Form Request classes:

- `StorePartnerRequest` — UUID format for tenant_id, enum validation for category
- `UpdatePartnerRequest` — Partial updates with `sometimes` rules
- `InviteGuestRequest` — Email format, URL format for redirect
- `StoreTemplateRequest` — Array validation for policy_config

### CSRF Protection

Inertia.js automatically handles CSRF tokens for all requests. The `VerifyCsrfToken` middleware is active on all POST/PUT/PATCH/DELETE routes.

### Audit Trail

Every significant action is logged to the `activity_log` table:

| Action | Trigger |
|--------|---------|
| `partner_created` | New partner organization added |
| `partner_updated` | Partner policy or details changed |
| `partner_deleted` | Partner removed |
| `guest_invited` | B2B invitation sent |
| `guest_removed` | Guest user deleted |
| `policy_changed` | Cross-tenant policy modified |
| `template_created` | New onboarding template created |
| `sync_completed` | Background sync finished |

Each log entry records: user, action, subject (polymorphic), description, details JSON, and timestamp.

## Default User Role

New users are created with the `viewer` role by default. An admin must promote users to `operator` or `admin` via the database or a future admin interface.

```php
// database/factories/UserFactory.php
'role' => 'viewer',

// migration
$table->string('role')->default('viewer');
```
