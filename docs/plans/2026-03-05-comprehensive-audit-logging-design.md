# Comprehensive Audit Logging & SIEM Export Design

## Overview

Expand Partner365's activity logging to provide full security audit coverage and add syslog/CEF forwarding to LogRhythm. Three workstreams: fill logging gaps, add syslog export with admin configuration, and enhance the activity log UI with filtering.

## 1. Logging Coverage Gaps

### New ActivityAction Enum Values

| Action | Trigger |
|--------|---------|
| `TemplateUpdated` | `PartnerTemplateController::update()` |
| `TemplateDeleted` | `PartnerTemplateController::destroy()` |
| `UserLoggedIn` | Laravel `Login` event listener |
| `UserLoggedOut` | Laravel `Logout` event listener |
| `LoginFailed` | Laravel `Failed` event listener |
| `AccountLocked` | Laravel `Lockout` event listener |
| `PasswordChanged` | `PasswordController::update()` |
| `TwoFactorEnabled` | Fortify 2FA enable action |
| `TwoFactorDisabled` | Fortify 2FA disable action |
| `ProfileUpdated` | `ProfileController::update()` |
| `AccountDeleted` | `ProfileController::destroy()` |
| `GraphConnectionTested` | `AdminGraphController::testConnection()` |
| `ConsentGranted` | `AdminGraphController::consentCallback()` |

### Wire Up Unused Enums

- `AccessReviewCompleted` — log when review status transitions to completed
- `ConditionalAccessPoliciesSynced` — log in `SyncConditionalAccessPolicies` command

### Sync Command Logging

`SyncPartners`, `SyncGuests`, `SyncEntitlements`, `SyncAccessReviews`, and `SyncConditionalAccessPolicies` will write `SyncCompleted` entries to `activity_log` with details (records synced, errors). `user_id` is null for system-triggered syncs.

### Auth Event Listeners

Register Laravel event listeners for Fortify-fired events: `Login`, `Logout`, `Failed`, `Lockout`. Each listener calls `ActivityLogService::log()` with the appropriate enum value. Entra ID SSO events are not captured here — Entra ID has its own audit logs that LogRhythm can ingest directly.

## 2. Syslog/CEF Export

### CEF Message Format

```
CEF:0|Partner365|Partner365|1.0|<action>|<action label>|<severity>|src=<user_ip> suser=<username> msg=<details> cs1=<subject_type> cs1Label=SubjectType cs2=<subject_id> cs2Label=SubjectId rt=<timestamp>
```

### New Components

- **`CefFormatter`** — Formats an `ActivityLog` record into a CEF string. Contains the severity mapping per `ActivityAction`. Handles CEF field escaping (pipes, backslashes).
- **`SyslogTransport`** — Handles UDP, TCP, and TLS socket connections to the syslog destination. Reconnects on failure.
- **`SyslogExportService`** — Orchestrates formatting + transport. Called from a queued job triggered by `ActivityLog` creation.

### Severity Mapping

Hardcoded per `ActivityAction`:

| Severity | Events |
|----------|--------|
| 3 (Low) | SyncCompleted, SyncTriggered, AccessReviewCreated, TemplateCreated |
| 5 (Medium) | PartnerCreated/Updated, GuestInvited/Updated/Enabled, TemplateUpdated, ProfileUpdated, AccessPackage CRUD, AssignmentRequested/Approved, UserLoggedIn, UserLoggedOut |
| 7 (High) | PartnerDeleted, GuestRemoved/Disabled, TemplateDeleted, UserDeleted, AssignmentRevoked/Denied, AccessReviewRemediationApplied, AccountDeleted |
| 8 (Very High) | LoginFailed, AccountLocked, PasswordChanged, TwoFactorDisabled, SettingsUpdated, UserRoleChanged, ConsentGranted, GraphConnectionTested |

### Delivery Pattern

1. Controller logs activity via `ActivityLogService::log()` (existing, synchronous)
2. Eloquent `ActivityLog::created` model observer fires
3. Observer dispatches a queued `ForwardToSyslog` job
4. Job formats to CEF via `CefFormatter` and sends via `SyslogTransport`

Logging is always synchronous to the database. Syslog forwarding is async via the queue — never blocks requests, retries on failure.

## 3. Admin Settings — SIEM Integration

### Settings Fields

Stored in the database following the existing admin settings pattern.

| Field | Type | Default |
|-------|------|---------|
| `syslog_enabled` | boolean | `false` |
| `syslog_host` | string | `null` |
| `syslog_port` | integer | `514` |
| `syslog_transport` | enum: `udp`, `tcp`, `tls` | `udp` |
| `syslog_facility` | integer | `16` (local0) |

### Admin UI

New "SIEM Integration" section in admin settings:

- Enable/disable toggle
- Host, port, transport protocol dropdown, facility code
- "Test Connection" button — sends a test CEF event and reports success/failure
- Validation: host required when enabled, port 1-65535

### RBAC

Admin-only, consistent with other admin settings.

## 4. Activity Log UI Enhancements

### Filters

Added to the existing `activity/Index.vue` page as a filter bar above the table:

- **Action type** — Multi-select dropdown grouped by category (Auth, Partners, Guests, Templates, Admin, Sync, Access Reviews, Entitlements)
- **User** — Searchable dropdown of users
- **Date range** — Start/end date pickers
- **Search** — Text search across the `details` JSON column

### Backend

`ActivityLogController::index()` accepts query parameters: `actions[]`, `user_id`, `date_from`, `date_to`, `search`. Filters applied to the Eloquent query before existing 50-per-page pagination.

No changes to table columns or layout.

## 5. Testing

| Area | Approach |
|------|----------|
| New enum logging | Verify all new actions get logged with proper details |
| Auth event listeners | Mock Fortify events, assert activity log entries created |
| CefFormatter | Unit test CEF output per severity tier, verify field escaping |
| SyslogExportService | Mock transport, verify dispatch on ActivityLog creation, verify nothing sent when disabled |
| SyslogTransport | Test connection validation, UDP/TCP/TLS config handling |
| Admin syslog settings | Test CRUD, validation rules, RBAC (admin-only) |
| Activity log filters | Test each filter individually and in combination |
| Test connection endpoint | Verify test event sent and success/failure reported |

All Graph API calls use `Http::fake()`. Syslog transport tests mock the socket layer.
