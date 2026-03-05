# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Partner365 is a Laravel 12 + Vue 3 application for managing Microsoft 365 external partner organizations, cross-tenant access policies, and B2B guest user lifecycle through the Microsoft Graph API. It uses Inertia.js for server-driven SPA rendering.

## Commands

```bash
# Development (runs Laravel server, queue worker, Vite dev server concurrently)
composer run dev

# Run full test suite (linting + Pest tests)
composer run test

# Run Pest tests only
php artisan test

# Run a single test file
php artisan test --filter=MicrosoftGraphServiceTest

# Full CI check (lint + format + types + tests)
composer run ci:check

# PHP linting (Laravel Pint)
composer run lint           # fix
composer run lint:check     # check only

# Frontend code quality
npm run lint                # ESLint fix
npm run format              # Prettier fix
npm run types:check         # TypeScript type checking
```

## Architecture

### Backend (Laravel 12, PHP 8.2+)

**Service Layer** — Business logic lives in `app/Services/`, not controllers:
- `MicrosoftGraphService` — Low-level HTTP client for Graph API with token caching
- `CrossTenantPolicyService` — Partner policy CRUD (wraps MicrosoftGraphService)
- `GuestUserService` — Guest invitations and sync
- `TenantResolverService` — Tenant info lookup from Graph API
- `SharePointSiteService` — SharePoint site sync and guest permissions
- `ActivityLogService` — Audit trail logging

**Data Sync Pattern** — Write-through + background reconciliation:
1. User action → Controller → Service writes to Graph API → Updates local DB
2. `sync:partners` and `sync:guests` commands run every 15 minutes to reconcile

**RBAC** — Three roles via `UserRole` enum: admin, operator, viewer. Enforced by `CheckRole` middleware on routes. Templates CRUD requires admin.

**Auth** — Laravel Fortify in development, Entra ID SSO in production.

### Frontend (Vue 3, TypeScript, Inertia.js)

- Pages in `resources/js/pages/` mirror route structure (partners/, guests/, templates/, activity/)
- UI components from shadcn-vue + Reka UI with Tailwind CSS
- All data comes as Inertia props from controllers — no client-side data fetching
- Composition API with `<script setup>` throughout
- Path alias: `@/*` → `resources/js/*`
- Types in `resources/js/types/`

### Routes

All in `routes/web.php`. Protected routes require `auth` + `verified` middleware. Resource routes for partners, guests, templates. Activity log is read-only.

### Testing (Pest PHP)

Tests in `tests/Feature/`. External Graph API calls are mocked with `Http::fake()`. Tests use in-memory SQLite (configured in `phpunit.xml`).

Mock pattern for Graph API:
```php
Http::fake([
    'login.microsoftonline.com/*' => Http::response([...]),
    'graph.microsoft.com/*' => Http::response([...]),
]);
```

### Key Enums

- `UserRole` — admin, operator, viewer (with `canManage()`, `isAdmin()`)
- `ActivityAction` — 8 audit action types
- `InvitationStatus` — pending_acceptance, accepted, failed
- `PartnerCategory` — partner classification

### Docker

Single-container deployment using FrankenPHP (Laravel Octane) + supervisord:
- `Dockerfile` — Multi-stage build (Node for frontend, FrankenPHP for runtime)
- `docker/supervisord.conf` — Manages octane, queue worker, scheduler
- `docker/entrypoint.sh` — Auto-migration, permissions, starts supervisord
- `docker-compose.yml` — Local container deployment
- `vite.config.ts` — Wayfinder plugin is skipped when `DOCKER_BUILD=1` (set in Dockerfile) since generated route helpers are already committed

```bash
# Build and run with Docker Compose
docker compose up -d --build

# Build image directly
docker build -t partner365:latest .

# Health check
curl http://localhost:8000/health
```

## Environment

Requires `MICROSOFT_GRAPH_TENANT_ID`, `MICROSOFT_GRAPH_CLIENT_ID`, `MICROSOFT_GRAPH_CLIENT_SECRET` in `.env`. Config in `config/graph.php`.
