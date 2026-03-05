# Dev Seeder Design

## Goal

Create a comprehensive, idempotent development seeder that populates Partner365 with realistic demo data covering all models, statuses, edge cases, and enough volume to test pagination and bulk actions.

## Approach

Single `DevSeeder` class called from `DatabaseSeeder` when `app.env` is `local`. Truncates all tables first (in FK-safe order) for idempotency.

## Data

**Users (~18):**
- 1 admin: `admin@partner365.dev` / `password` (approved)
- 5 operators (approved)
- 10 viewers (approved)
- 2 unapproved users (pending approval)

**Partners (~30):**
- Distributed across all 5 categories (vendor, contractor, strategic_partner, customer, other)
- Varied policy configurations: some all-on, some all-off, some mixed
- ~5 with no guests (empty state testing)
- Owned by different users

**Guests (~120):**
- Distributed across ~25 partners, ~5 orphaned (no partner)
- Email domains match partner domains where associated
- Status distribution: ~80 accepted, ~30 pending, ~10 failed
- Mix of enabled/disabled accounts
- Varied sign-in history: some recent, some never

**Templates (5):**
- Named presets: Restrictive, Permissive, MFA Only, Inbound Only, Standard

**Activity Logs (~200):**
- Spread across last 30 days
- Cover all ActivityAction types
- Tied to real subjects

**Sync Logs (~60):**
- 30 days of partner + guest syncs (twice daily)
- Mostly successful, a few failures

**Settings:**
- Graph API placeholder config (tenant_id, client_id, client_secret encrypted)
- Sync intervals at defaults (15 min)

## Idempotency

Truncates tables in this order before seeding:
1. activity_log
2. guest_users
3. partner_templates
4. partner_organizations
5. sync_logs
6. settings
7. users

Uses `DB::table()->truncate()` with foreign key checks disabled.
