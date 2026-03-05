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
| View guest access (groups, apps, teams, sites) | Yes | Yes | Yes |
| Invite guest | Yes | Yes | No |
| Delete guest | Yes | No | No |
| Manage templates | Yes | No | No |
| View access reviews | Yes | Yes | Yes |
| Create/delete access reviews | Yes | No | No |
| Submit review decisions | Yes | Yes | No |
| Apply remediations | Yes | No | No |
| View activity log | Yes | Yes | Yes |
| Configure SIEM/syslog | Yes | No | No |

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

Every significant action is logged to the `activity_log` table. An `ActivityLogObserver` automatically dispatches a queued `ForwardToSyslog` job on each new entry when syslog forwarding is enabled.

| Action | Trigger |
|--------|---------|
| **Auth Events** | |
| `user_logged_in` | Successful authentication |
| `user_logged_out` | User logout |
| `login_failed` | Failed login attempt |
| `account_locked` | Account locked after repeated failures |
| `password_changed` | User changed their password |
| `two_factor_enabled` | 2FA activated |
| `two_factor_disabled` | 2FA deactivated |
| **Partner Management** | |
| `partner_created` | New partner organization added |
| `partner_updated` | Partner policy or details changed |
| `partner_deleted` | Partner removed |
| `policy_changed` | Cross-tenant policy modified |
| **Guest Management** | |
| `guest_invited` | B2B invitation sent |
| `guest_updated` | Guest user details changed |
| `guest_enabled` | Guest account re-enabled |
| `guest_disabled` | Guest account disabled |
| `guest_removed` | Guest user deleted |
| **Templates** | |
| `template_created` | New onboarding template created |
| `template_updated` | Template configuration changed |
| `template_deleted` | Template removed |
| **Admin Actions** | |
| `settings_updated` | Admin settings changed |
| `user_approved` | User account approved |
| `user_role_changed` | User role updated |
| `user_deleted` | User account deleted |
| `graph_connection_tested` | Graph API connection test (success or failure) |
| `consent_granted` | Admin consent granted via popup |
| `profile_updated` | User updated their profile |
| `account_deleted` | User deleted their own account |
| **Sync Events** | |
| `sync_completed` | Background sync finished |
| `sync_triggered` | Manual sync triggered |
| `conditional_access_policies_synced` | CA policy sync completed |
| **Access Reviews** | |
| `access_review_created` | New access review configured |
| `access_review_completed` | Review instance completed |
| `access_review_decision_made` | Decision submitted on a review item |
| `access_review_remediation_applied` | Remediation actions applied to denied items |
| **Entitlements** | |
| `access_package_created` | Access package created |
| `access_package_updated` | Access package modified |
| `access_package_deleted` | Access package removed |
| `assignment_requested` | Assignment requested |
| `assignment_approved` | Assignment approved |
| `assignment_denied` | Assignment denied |
| `assignment_revoked` | Assignment revoked |

Each log entry records: user (nullable for system events), action, subject (polymorphic), description, details JSON, and timestamp.

### SIEM Integration

Activity logs can be forwarded to an external SIEM (e.g., LogRhythm) via syslog in CEF (Common Event Format). Configuration is managed through the admin SIEM Integration page (`/admin/syslog`):

- **Transport:** UDP (default), TCP, or TLS
- **Port:** Configurable (default 514)
- **Facility:** Syslog facility code 0-23 (default 16 = local0)
- **Forwarding:** Asynchronous via queued job — logging is never blocked by syslog delivery
- **Severity mapping:** CEF severity 3 (Low) for read/sync, 5 (Medium) for creates/updates, 7 (High) for deletes, 8 (Very High) for auth failures

## Default User Role

New users are created with the `viewer` role by default. An admin must promote users to `operator` or `admin` via the database or a future admin interface.

```php
// database/factories/UserFactory.php
'role' => 'viewer',

// migration
$table->string('role')->default('viewer');
```
