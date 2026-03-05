# Partner365

A purpose-built web application for managing Microsoft 365 external partner organizations, cross-tenant access policies, MFA trust settings, and B2B guest user lifecycle — all through the Microsoft Graph API.

## Why Partner365?

Managing 100+ external partners through the Entra ID admin center is painful:

- **No consolidated dashboard** — partner policies are buried across multiple admin pages
- **Manual onboarding** — each partner requires repetitive clicks through the same settings
- **No guest lifecycle tracking** — inactive guests and pending invitations go unnoticed

Partner365 solves this with a single interface for IT admins, business owners, and helpdesk staff.

## Features

- **Partner Management** — View, create, update, and delete cross-tenant access policies with simple toggle controls
- **B2B Direct Connect** — Separate inbound/outbound controls with combined status display
- **Tenant Restrictions v2** — Per-partner app access controls with application targeting
- **External Collaboration Settings** — Admin controls for guest invitation policies and domain allow/block lists
- **Guest User Lifecycle** — Invite B2B guests, track invitation status, monitor sign-in activity, identify inactive accounts
- **Guest Access Visibility** — Live view of each guest user's group memberships, app assignments, Teams memberships, and SharePoint site access via Graph API
- **GCC High Support** — Cloud environment selector for Commercial and GCC High tenants with auto-derived endpoints
- **Admin Consent** — One-click admin consent popup for granting Graph API permissions
- **Trust Score** — Composite 0-100 domain reputation score per partner based on DNS hygiene (DMARC, SPF, DKIM, DNSSEC, domain age) and Entra ID metadata, recalculated daily
- **Compliance Reports** — Unified compliance dashboard with partner policy compliance scoring, stale guest detection (30/60/90-day buckets), filterable tables by issue type, and CSV export for audit documentation
- **Dashboard** — Action center with key stats (partners, guests, stale guests, pending invitations, overdue reviews), triage section (pending entitlement approvals with inline approve/deny, partners needing attention with trust scores), and recent activity feed
- **Onboarding Wizard** — 3-step partner creation with tenant resolution, optional templates, and policy configuration
- **Partner Templates** — Reusable policy configurations for consistent onboarding
- **Partner Favicons** — Automatic daily fetch and cache of partner organization favicons from their domains, displayed on index and detail pages with initials fallback
- **Background Sync** — Automatic 15-minute sync from Graph API keeps local data current
- **Access Reviews** — Periodic certification of guest user and partner organization access with configurable remediation (flag, disable, remove)
- **Conditional Access Visibility** — Read-only view of Conditional Access policies targeting guest/external users, per-partner policy mapping with gap detection for uncovered partners
- **Sensitivity Label Visibility** — Read-only view of Microsoft Information Protection sensitivity labels, per-partner impact mapping via label policies and site assignments, gap detection for uncovered partners
- **Entitlement Management** — Self-service access packages for external partner users with bundled group memberships and SharePoint site access, single-stage approval workflows, configurable expiration, and Graph API integration
- **Activity Log** — Full audit trail of all actions with filtering by action type, user, date range, and keyword search
- **SIEM Integration** — Syslog/CEF forwarding to LogRhythm (or any SIEM) with admin-configurable host, port, transport (UDP/TCP/TLS), and facility
- **Comprehensive Audit Coverage** — Auth events (login, logout, lockout, 2FA), profile/password changes, template CRUD, sync completions, admin actions, and consent grants
- **3-Tier RBAC** — Admin, Operator, and Viewer roles with middleware-enforced access control

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 12 (PHP 8.2+) |
| Frontend | Vue 3 + Inertia.js |
| UI Components | shadcn-vue + Tailwind CSS |
| API Integration | Microsoft Graph API v1.0 (direct HTTP, no SDK) |
| Testing | Pest PHP |
| Database | SQLite (dev/single-server) / PostgreSQL (prod) |
| Auth | Laravel Fortify (dev) / Entra ID SSO (prod) |
| Deployment | Docker (FrankenPHP + Octane) or bare metal |

## Quick Start

### Docker (Recommended)

```bash
git clone https://github.com/nathanphelps/partner365.git
cd partner365

cp .env.example .env
# Edit .env — set APP_KEY, Graph API credentials

docker compose up -d --build
# App is running at http://localhost:8000
```

### Local Development

**Prerequisites:** PHP 8.2+, Composer, Node.js 18+, an Azure AD app registration

```bash
git clone https://github.com/nathanphelps/partner365.git
cd partner365

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Add your Azure AD app registration credentials to `.env`:

```env
MICROSOFT_GRAPH_TENANT_ID=your-tenant-id
MICROSOFT_GRAPH_CLIENT_ID=your-client-id
MICROSOFT_GRAPH_CLIENT_SECRET=your-client-secret
```

See [docs/azure-setup.md](docs/azure-setup.md) for detailed Azure configuration instructions.

```bash
php artisan migrate
composer run dev    # Starts Laravel, queue worker, and Vite dev server
```

### Run Tests

```bash
php artisan test
```

## Documentation

| Document | Description |
|----------|-------------|
| [Architecture](docs/architecture.md) | System design, data model, and API integration patterns |
| [Azure Setup](docs/azure-setup.md) | App registration, permissions, and credential configuration |
| [API Reference](docs/api-reference.md) | All routes, controllers, and request/response formats |
| [RBAC & Security](docs/rbac-and-security.md) | Role definitions, middleware, and security model |
| [Graph API Integration](docs/graph-api-integration.md) | Service layer, endpoints, token management, and sync |
| [Frontend Guide](docs/frontend-guide.md) | Vue pages, components, routing, and UI patterns |
| [Testing](docs/testing.md) | Test suite structure, running tests, and mocking Graph API |
| [Deployment](docs/deployment.md) | Production setup, scheduling, and environment configuration |

## Project Structure

```
docker/
├── entrypoint.sh              # Container startup (migrations, permissions, supervisord)
└── supervisord.conf           # Process manager for web, queue, scheduler
Dockerfile                     # Multi-stage build (Node + FrankenPHP)
docker-compose.yml             # Local container deployment

app/
├── Console/Commands/       # sync:partners, sync:guests, sync:favicons, sync:access-reviews, sync:entitlements, sync:conditional-access-policies, sync:sensitivity-labels, score:partners
├── Enums/                  # UserRole, PartnerCategory, InvitationStatus, ActivityAction, CloudEnvironment,
│                           # ReviewType, RecurrenceType, RemediationAction, ReviewInstanceStatus, ReviewDecision,
│                           # AccessPackageResourceType, AssignmentStatus
├── Exceptions/             # GraphApiException
├── Http/
│   ├── Controllers/        # Partner, Guest, Template, Dashboard, ComplianceReport, ActivityLog, AccessReview, ConditionalAccessPolicy, SensitivityLabel, Entitlement, Admin
│   ├── Middleware/          # CheckRole (RBAC)
│   └── Requests/           # StorePartner, UpdatePartner, InviteGuest, StoreTemplate, UpdateCollaboration,
│                           # StoreAccessReview, StoreAccessPackage, UpdateAccessPackage, UpdateSyslogSettings
├── Jobs/                   # ForwardToSyslog (queued CEF forwarding)
├── Listeners/              # LogAuthEvent (Login, Logout, Failed, Lockout, 2FA)
├── Models/                 # PartnerOrganization, GuestUser, PartnerTemplate, ActivityLog, Setting,
│                           # AccessReview, AccessReviewInstance, AccessReviewDecision,
│                           # ConditionalAccessPolicy, SensitivityLabel, SensitivityLabelPolicy, SiteSensitivityLabel,
│                           # AccessPackageCatalog, AccessPackage, AccessPackageResource, AccessPackageAssignment
├── Observers/              # ActivityLogObserver (auto-dispatches syslog forwarding)
└── Services/               # MicrosoftGraphService, CrossTenantPolicyService,
                            # GuestUserService, TenantResolverService,
                            # CollaborationSettingsService, ActivityLogService,
                            # AccessReviewService, ConditionalAccessPolicyService,
                            # SensitivityLabelService, EntitlementService,
                            # TrustScoreService, DnsLookupService, FaviconService
    └── Syslog/             # CefFormatter (CEF string builder), SyslogTransport (UDP/TCP/TLS)

resources/js/
├── pages/
│   ├── partners/           # Index, Show, Create (wizard)
│   ├── guests/             # Index, Show, Invite
│   ├── templates/          # Index, Create, Edit
│   ├── reports/            # Index (compliance dashboard with CSV export)
│   ├── access-reviews/     # Index, Create, Show, Instance
│   ├── conditional-access/ # Index, Show (read-only CA policy visibility)
│   ├── sensitivity-labels/ # Index, Show (read-only sensitivity label visibility)
│   ├── entitlements/       # Index, Create (multi-step wizard), Show
│   ├── admin/              # Graph settings, Collaboration, Users, Sync, Syslog
│   ├── activity/           # Index
│   └── Dashboard.vue
├── types/                  # TypeScript types for Partner, Guest (+ access types), AccessReview, ConditionalAccessPolicy, SensitivityLabel, Entitlement, Compliance, Paginated
└── components/             # shadcn-vue UI components + TrustScoreBadge + PartnerAvatar

tests/Feature/
├── Auth/                   # Fortify auth tests + AuthAuditLoggingTest
├── Commands/               # SyncPartners, SyncGuests, SyncAccessReviews, SyncConditionalAccessPolicies, SyncSensitivityLabels, SyncActivityLogging
├── Controllers/            # ConditionalAccessPolicy, SensitivityLabel, PartnerTemplate, ActivityLog controller tests
├── Jobs/                   # ForwardToSyslogTest
├── Middleware/              # CheckRole
├── Models/                 # PartnerOrganization
├── Observers/              # ActivityLogObserverTest
├── Services/               # All Graph API service classes + SensitivityLabelService + Syslog/ (CefFormatter, SyslogTransport)
├── Settings/               # Profile, Password, 2FA, AuditLogging tests
├── Admin/                  # AdminGraph, AdminSync, AdminUser, AdminSyslog controller tests
├── PartnerOrganizationTest.php
├── GuestUserControllerTest.php
├── ComplianceReportTest.php
├── CollaborationSettingsTest.php
├── AccessReviewServiceTest.php
├── AccessReviewControllerTest.php
├── EntitlementServiceTest.php
├── EntitlementControllerTest.php
├── TrustScoreServiceTest.php
├── DnsLookupServiceTest.php
├── FaviconServiceTest.php
├── SyncFaviconsCommandTest.php
└── ScorePartnersCommandTest.php
```

## License

Private — all rights reserved.
