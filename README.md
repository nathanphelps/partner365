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
- **GCC High Support** — Cloud environment selector for Commercial and GCC High tenants with auto-derived endpoints
- **Admin Consent** — One-click admin consent popup for granting Graph API permissions
- **Dashboard** — At-a-glance stats for partners, guests, MFA trust coverage, and pending invitations
- **Onboarding Wizard** — 3-step partner creation with tenant resolution, optional templates, and policy configuration
- **Partner Templates** — Reusable policy configurations for consistent onboarding
- **Background Sync** — Automatic 15-minute sync from Graph API keeps local data current
- **Activity Log** — Full audit trail of all partner and guest management actions
- **3-Tier RBAC** — Admin, Operator, and Viewer roles with middleware-enforced access control

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 12 (PHP 8.2+) |
| Frontend | Vue 3 + Inertia.js |
| UI Components | shadcn-vue + Tailwind CSS |
| API Integration | Microsoft Graph API v1.0 (direct HTTP, no SDK) |
| Testing | Pest PHP (160 tests) |
| Database | SQLite (dev) / PostgreSQL (prod) |
| Auth | Laravel Fortify (dev) / Entra ID SSO (prod) |

## Quick Start

### Prerequisites

- PHP 8.2+
- Composer
- Node.js 18+
- An Azure AD app registration with the required Graph API permissions

### Installation

```bash
# Clone the repository
git clone https://github.com/nathanphelps/partner365.git
cd partner365

# Install dependencies
composer install
npm install

# Configure environment
cp .env.example .env
php artisan key:generate
```

### Configure Graph API

Add your Azure AD app registration credentials to `.env`:

```env
MICROSOFT_GRAPH_TENANT_ID=your-tenant-id
MICROSOFT_GRAPH_CLIENT_ID=your-client-id
MICROSOFT_GRAPH_CLIENT_SECRET=your-client-secret
```

See [docs/azure-setup.md](docs/azure-setup.md) for detailed Azure configuration instructions.

### Run

```bash
# Run migrations
php artisan migrate

# Build frontend
npm run build

# Start the dev server
php artisan serve

# In a separate terminal, for hot-reload during development:
npm run dev
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
app/
├── Console/Commands/       # sync:partners, sync:guests
├── Enums/                  # UserRole, PartnerCategory, InvitationStatus, ActivityAction, CloudEnvironment
├── Exceptions/             # GraphApiException
├── Http/
│   ├── Controllers/        # Partner, Guest, Template, Dashboard, ActivityLog, Admin
│   ├── Middleware/          # CheckRole (RBAC)
│   └── Requests/           # StorePartner, UpdatePartner, InviteGuest, StoreTemplate, UpdateCollaboration
├── Models/                 # PartnerOrganization, GuestUser, PartnerTemplate, ActivityLog
└── Services/               # MicrosoftGraphService, CrossTenantPolicyService,
                            # GuestUserService, TenantResolverService,
                            # CollaborationSettingsService, ActivityLogService

resources/js/
├── pages/
│   ├── partners/           # Index, Show, Create (wizard)
│   ├── guests/             # Index, Show, Invite
│   ├── templates/          # Index, Create, Edit
│   ├── admin/              # Graph settings, Collaboration, Users, Sync
│   ├── activity/           # Index
│   └── Dashboard.vue
├── types/                  # TypeScript types for Partner, Guest, Paginated
└── components/             # shadcn-vue UI components

tests/Feature/
├── Commands/               # SyncPartners, SyncGuests
├── Middleware/              # CheckRole
├── Models/                 # PartnerOrganization
├── Services/               # All 5 service classes
├── PartnerOrganizationTest.php
├── GuestUserControllerTest.php
└── CollaborationSettingsTest.php
```

## License

Private — all rights reserved.
