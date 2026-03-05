# Compliance Reporting & Stale Guest Detection Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a unified compliance reporting page at `/reports` covering partner policy compliance and guest account health, with CSV export and guest list enhancements.

**Architecture:** Server-side aggregation in `ComplianceReportController`, Inertia props to `reports/Index.vue`. No new models or migrations — queries existing `partner_organizations`, `guest_users`, and `conditional_access_policies` tables. CSV export via Laravel's `StreamedResponse`.

**Tech Stack:** Laravel 12 / Pest PHP / Vue 3 + TypeScript / Inertia.js / shadcn-vue / Tailwind CSS

**Design doc:** `docs/plans/2026-03-05-compliance-reporting-design.md`

---

### Task 1: ComplianceReportController — index method

**Files:**
- Create: `app/Http/Controllers/ComplianceReportController.php`
- Modify: `routes/web.php:19-50` (add routes inside auth middleware group)
- Test: `tests/Feature/ComplianceReportTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/ComplianceReportTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => UserRole::Admin]);
});

test('unauthenticated users cannot access reports', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

test('authenticated users can access reports page', function () {
    $this->actingAs($this->user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/Index')
            ->has('partnerCompliance')
            ->has('guestHealth')
            ->has('summary')
        );
});

test('all roles can access reports', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertOk();
})->with([UserRole::Admin, UserRole::Operator, UserRole::Viewer]);

test('compliance score is calculated correctly', function () {
    // Compliant: MFA enabled + not overly permissive
    PartnerOrganization::factory()->create([
        'mfa_trust_enabled' => true,
        'b2b_inbound_enabled' => true,
        'b2b_outbound_enabled' => false,
    ]);

    // Non-compliant: no MFA
    PartnerOrganization::factory()->create([
        'mfa_trust_enabled' => false,
        'b2b_inbound_enabled' => false,
        'b2b_outbound_enabled' => false,
    ]);

    // Non-compliant: overly permissive
    PartnerOrganization::factory()->create([
        'mfa_trust_enabled' => true,
        'b2b_inbound_enabled' => true,
        'b2b_outbound_enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.compliance_score', 33) // 1 of 3 compliant
            ->where('summary.partners_with_issues', 2)
            ->where('partnerCompliance.no_mfa_count', 1)
            ->where('partnerCompliance.overly_permissive_count', 1)
        );
});

test('stale guest buckets are computed correctly', function () {
    $partner = PartnerOrganization::factory()->create();

    // Active guest (signed in 10 days ago)
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => now()->subDays(10),
        'invitation_status' => 'accepted',
    ]);

    // 30+ day stale guest
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => now()->subDays(45),
        'invitation_status' => 'accepted',
    ]);

    // 60+ day stale guest
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => now()->subDays(75),
        'invitation_status' => 'accepted',
    ]);

    // 90+ day stale guest
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => now()->subDays(100),
        'invitation_status' => 'accepted',
    ]);

    // Never signed in
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => null,
        'invitation_status' => 'accepted',
    ]);

    $this->actingAs($this->user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('guestHealth.stale_30_plus', 4) // 45, 75, 100, null
            ->where('guestHealth.stale_60_plus', 3) // 75, 100, null
            ->where('guestHealth.stale_90_plus', 2) // 100, null
            ->where('guestHealth.never_signed_in', 1)
        );
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ComplianceReportTest`
Expected: FAIL — route `reports.index` not defined

**Step 3: Add the routes**

Modify `routes/web.php`. Inside the `Route::middleware(['auth', 'verified', 'approved'])` group, add after the activity route (around line 34):

```php
use App\Http\Controllers\ComplianceReportController;

// ... inside middleware group:
Route::get('reports', [ComplianceReportController::class, 'index'])->name('reports.index');
Route::get('reports/export', [ComplianceReportController::class, 'export'])->name('reports.export');
```

**Step 4: Create the controller**

Create `app/Http/Controllers/ComplianceReportController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComplianceReportController extends Controller
{
    public function index(Request $request): Response
    {
        $totalPartners = PartnerOrganization::count();

        // Partner compliance metrics
        $noMfa = PartnerOrganization::where('mfa_trust_enabled', false)->get(['id', 'display_name', 'domain', 'tenant_id']);
        $noDeviceTrust = PartnerOrganization::where('device_trust_enabled', false)->get(['id', 'display_name', 'domain', 'tenant_id']);
        $overlyPermissive = PartnerOrganization::where('b2b_inbound_enabled', true)
            ->where('b2b_outbound_enabled', true)
            ->get(['id', 'display_name', 'domain', 'tenant_id']);

        $partnersWithCaPolicies = \DB::table('conditional_access_policy_partner')
            ->distinct('partner_organization_id')
            ->pluck('partner_organization_id');
        $noCaPolicies = PartnerOrganization::whereNotIn('id', $partnersWithCaPolicies)
            ->get(['id', 'display_name', 'domain', 'tenant_id']);

        // Compliance score: MFA enabled AND not overly permissive
        $compliantCount = PartnerOrganization::where('mfa_trust_enabled', true)
            ->where(function ($q) {
                $q->where('b2b_inbound_enabled', false)
                    ->orWhere('b2b_outbound_enabled', false);
            })
            ->count();

        $complianceScore = $totalPartners > 0
            ? (int) round(($compliantCount / $totalPartners) * 100)
            : 100;

        $avgTrustScore = PartnerOrganization::whereNotNull('trust_score')->avg('trust_score');

        // Guest health metrics
        $now = now();
        $totalGuests = GuestUser::count();
        $stale30 = GuestUser::where(function ($q) use ($now) {
            $q->where('last_sign_in_at', '<', $now->copy()->subDays(30))
                ->orWhereNull('last_sign_in_at');
        })->count();
        $stale60 = GuestUser::where(function ($q) use ($now) {
            $q->where('last_sign_in_at', '<', $now->copy()->subDays(60))
                ->orWhereNull('last_sign_in_at');
        })->count();
        $stale90 = GuestUser::where(function ($q) use ($now) {
            $q->where('last_sign_in_at', '<', $now->copy()->subDays(90))
                ->orWhereNull('last_sign_in_at');
        })->count();
        $neverSignedIn = GuestUser::whereNull('last_sign_in_at')->count();
        $pendingInvitations = GuestUser::where('invitation_status', 'pending_acceptance')->count();
        $disabledAccounts = GuestUser::where('account_enabled', false)->count();

        // Stale guest list (90+ days or never signed in, for the table)
        $staleGuests = GuestUser::with('partnerOrganization:id,display_name')
            ->where(function ($q) use ($now) {
                $q->where('last_sign_in_at', '<', $now->copy()->subDays(30))
                    ->orWhereNull('last_sign_in_at');
            })
            ->orderBy('last_sign_in_at')
            ->get(['id', 'email', 'display_name', 'partner_organization_id', 'last_sign_in_at', 'invitation_status', 'account_enabled']);

        // Non-compliant partner list (has at least one issue)
        $nonCompliantIds = $noMfa->pluck('id')
            ->merge($noDeviceTrust->pluck('id'))
            ->merge($overlyPermissive->pluck('id'))
            ->merge($noCaPolicies->pluck('id'))
            ->unique();

        $nonCompliantPartners = PartnerOrganization::whereIn('id', $nonCompliantIds)
            ->withCount(['conditionalAccessPolicies'])
            ->get(['id', 'display_name', 'domain', 'mfa_trust_enabled', 'device_trust_enabled', 'b2b_inbound_enabled', 'b2b_outbound_enabled', 'trust_score']);

        return Inertia::render('reports/Index', [
            'summary' => [
                'compliance_score' => $complianceScore,
                'partners_with_issues' => $nonCompliantIds->count(),
                'stale_guests_90' => $stale90,
                'total_partners' => $totalPartners,
                'total_guests' => $totalGuests,
                'avg_trust_score' => $avgTrustScore ? round($avgTrustScore, 1) : null,
            ],
            'partnerCompliance' => [
                'no_mfa_count' => $noMfa->count(),
                'no_device_trust_count' => $noDeviceTrust->count(),
                'overly_permissive_count' => $overlyPermissive->count(),
                'no_ca_policies_count' => $noCaPolicies->count(),
                'partners' => $nonCompliantPartners,
            ],
            'guestHealth' => [
                'stale_30_plus' => $stale30,
                'stale_60_plus' => $stale60,
                'stale_90_plus' => $stale90,
                'never_signed_in' => $neverSignedIn,
                'pending_invitations' => $pendingInvitations,
                'disabled_accounts' => $disabledAccounts,
                'guests' => $staleGuests,
            ],
        ]);
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=ComplianceReportTest`
Expected: Tests should mostly pass. Some may fail if the Inertia test assertions need the Vue component to exist — create a placeholder `reports/Index.vue` if needed:

```vue
<script setup lang="ts">
defineProps<{
    summary: Record<string, unknown>;
    partnerCompliance: Record<string, unknown>;
    guestHealth: Record<string, unknown>;
}>();
</script>
<template><div>Reports placeholder</div></template>
```

**Step 6: Commit**

```bash
git add app/Http/Controllers/ComplianceReportController.php routes/web.php tests/Feature/ComplianceReportTest.php resources/js/pages/reports/Index.vue
git commit -m "feat: add ComplianceReportController with index route and tests

Closes #9 (partial), closes #7 (partial)"
```

---

### Task 2: CSV Export

**Files:**
- Modify: `app/Http/Controllers/ComplianceReportController.php`
- Test: `tests/Feature/ComplianceReportTest.php` (append tests)

**Step 1: Write the failing test**

Append to `tests/Feature/ComplianceReportTest.php`:

```php
test('csv export returns downloadable file with correct headers', function () {
    PartnerOrganization::factory()->create(['display_name' => 'Acme Corp']);
    GuestUser::factory()->create(['email' => 'guest@acme.com']);

    $response = $this->actingAs($this->user)
        ->get(route('reports.export'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('Content-Disposition', 'attachment; filename="compliance-report.csv"');

    $content = $response->streamedContent();
    expect($content)->toContain('Partner Name');
    expect($content)->toContain('Acme Corp');
    expect($content)->toContain('Guest Email');
    expect($content)->toContain('guest@acme.com');
});

test('unauthenticated users cannot export csv', function () {
    $this->get(route('reports.export'))->assertRedirect(route('login'));
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ComplianceReportTest`
Expected: FAIL — `export` method not defined on controller

**Step 3: Add export method to controller**

Add to `app/Http/Controllers/ComplianceReportController.php`:

```php
use Symfony\Component\HttpFoundation\StreamedResponse;

public function export(Request $request): StreamedResponse
{
    $partners = PartnerOrganization::withCount('conditionalAccessPolicies')
        ->orderBy('display_name')
        ->get();

    $guests = GuestUser::with('partnerOrganization:id,display_name')
        ->orderBy('last_sign_in_at')
        ->get();

    return response()->stream(function () use ($partners, $guests) {
        $handle = fopen('php://output', 'w');

        // Partner section
        fputcsv($handle, ['--- Partner Policy Compliance ---']);
        fputcsv($handle, ['Partner Name', 'Domain', 'MFA Trust', 'Device Trust', 'B2B Inbound', 'B2B Outbound', 'Trust Score', 'CA Policy Count']);

        foreach ($partners as $partner) {
            fputcsv($handle, [
                $partner->display_name,
                $partner->domain,
                $partner->mfa_trust_enabled ? 'Yes' : 'No',
                $partner->device_trust_enabled ? 'Yes' : 'No',
                $partner->b2b_inbound_enabled ? 'Yes' : 'No',
                $partner->b2b_outbound_enabled ? 'Yes' : 'No',
                $partner->trust_score,
                $partner->conditional_access_policies_count,
            ]);
        }

        fputcsv($handle, []);

        // Guest section
        fputcsv($handle, ['--- Guest Account Health ---']);
        fputcsv($handle, ['Guest Email', 'Display Name', 'Partner', 'Last Sign-In', 'Days Inactive', 'Invitation Status', 'Account Enabled']);

        foreach ($guests as $guest) {
            $daysInactive = $guest->last_sign_in_at
                ? (int) now()->diffInDays($guest->last_sign_in_at)
                : null;

            fputcsv($handle, [
                $guest->email,
                $guest->display_name,
                $guest->partnerOrganization?->display_name,
                $guest->last_sign_in_at?->format('Y-m-d'),
                $daysInactive ?? 'Never',
                $guest->invitation_status,
                $guest->account_enabled ? 'Yes' : 'No',
            ]);
        }

        fclose($handle);
    }, 200, [
        'Content-Type' => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="compliance-report.csv"',
    ]);
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ComplianceReportTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Controllers/ComplianceReportController.php tests/Feature/ComplianceReportTest.php
git commit -m "feat: add CSV export for compliance reports"
```

---

### Task 3: TypeScript types for compliance report

**Files:**
- Create: `resources/js/types/compliance.ts`
- Modify: `resources/js/types/index.ts` (add export)

**Step 1: Create the type file**

Create `resources/js/types/compliance.ts`:

```typescript
import type { GuestUser } from './guest';
import type { PartnerOrganization } from './partner';

export type ComplianceSummary = {
    compliance_score: number;
    partners_with_issues: number;
    stale_guests_90: number;
    total_partners: number;
    total_guests: number;
    avg_trust_score: number | null;
};

export type NonCompliantPartner = Pick<
    PartnerOrganization,
    'id' | 'display_name' | 'domain' | 'mfa_trust_enabled' | 'device_trust_enabled' | 'b2b_inbound_enabled' | 'b2b_outbound_enabled' | 'trust_score'
> & {
    conditional_access_policies_count: number;
};

export type PartnerCompliance = {
    no_mfa_count: number;
    no_device_trust_count: number;
    overly_permissive_count: number;
    no_ca_policies_count: number;
    partners: NonCompliantPartner[];
};

export type StaleGuest = Pick<
    GuestUser,
    'id' | 'email' | 'display_name' | 'last_sign_in_at' | 'invitation_status' | 'account_enabled'
> & {
    partner_organization?: { id: number; display_name: string } | null;
};

export type GuestHealth = {
    stale_30_plus: number;
    stale_60_plus: number;
    stale_90_plus: number;
    never_signed_in: number;
    pending_invitations: number;
    disabled_accounts: number;
    guests: StaleGuest[];
};
```

**Step 2: Add export to index**

Modify `resources/js/types/index.ts` — add line:

```typescript
export * from './compliance';
```

**Step 3: Commit**

```bash
git add resources/js/types/compliance.ts resources/js/types/index.ts
git commit -m "feat: add TypeScript types for compliance reporting"
```

---

### Task 4: Reports page — Vue component

**Files:**
- Modify: `resources/js/pages/reports/Index.vue` (replace placeholder)
- Modify: `resources/js/components/AppSidebar.vue` (add nav item)

**Step 1: Add navigation item**

Modify `resources/js/components/AppSidebar.vue`:

Add to imports (around line 1-13, with the other lucide icons):
```typescript
import { BarChart3 } from 'lucide-vue-next';
```

Add to `mainNavItems` array (around line 53, before Activity):
```typescript
{ title: 'Reports', href: '/reports', icon: BarChart3 },
```

**Step 2: Build the reports page**

Replace `resources/js/pages/reports/Index.vue` with the full implementation. The page has three sections:

1. **Summary bar** — Three stat cards showing compliance score, partners with issues, stale guests (90+)
2. **Partner compliance table** — Filterable by issue type (tabs: All | No MFA | Overly Permissive | No CA Policies). Each partner row shows badges for MFA, device trust, B2B openness, CA policy count. Partner name links to `/partners/{id}`.
3. **Guest health table** — Filterable by staleness bucket (tabs: All | 30+ | 60+ | 90+ | Never Signed In). Each row shows guest email (links to `/guests/{id}`), partner name, last sign-in date, days inactive badge (green/yellow/orange/red), status.
4. **Export button** — Top-right, links to `/reports/export`

Use these existing shadcn-vue components (already available in the project):
- `Card`, `CardHeader`, `CardTitle`, `CardContent` from `@/components/ui/card`
- `Badge` from `@/components/ui/badge`
- `Button` from `@/components/ui/button`
- `Table`, `TableBody`, `TableCell`, `TableHead`, `TableHeader`, `TableRow` from `@/components/ui/table`
- `Tabs`, `TabsList`, `TabsTrigger` from `@/components/ui/tabs` (if available, otherwise use simple button group)

Layout pattern: Follow `resources/js/pages/Dashboard.vue` for the overall structure (AppLayout, Head, grid of cards). Follow `resources/js/pages/guests/Index.vue` and `resources/js/components/GuestUserTable.vue` for table rendering patterns.

Props:
```typescript
import type { ComplianceSummary, PartnerCompliance, GuestHealth } from '@/types/compliance';

const props = defineProps<{
    summary: ComplianceSummary;
    partnerCompliance: PartnerCompliance;
    guestHealth: GuestHealth;
}>();
```

For the compliance score card, use color coding:
- Score >= 80: green (`text-green-600`)
- Score >= 50: yellow (`text-yellow-600`)
- Score < 50: red (`text-red-600`)

For days inactive badge:
```typescript
function inactiveBadgeVariant(lastSignIn: string | null): string {
    if (!lastSignIn) return 'destructive';
    const days = Math.floor((Date.now() - new Date(lastSignIn).getTime()) / 86400000);
    if (days >= 90) return 'destructive';
    if (days >= 60) return 'warning'; // or use orange classes
    if (days >= 30) return 'secondary';
    return 'default';
}
```

Client-side filtering: Use a `ref<string>` for each tab section (`partnerFilter`, `guestFilter`) and `computed` to filter the arrays. No server round-trips needed since all data is in props.

**Step 3: Run type check**

Run: `npm run types:check`
Expected: PASS (no TypeScript errors)

**Step 4: Commit**

```bash
git add resources/js/pages/reports/Index.vue resources/js/components/AppSidebar.vue
git commit -m "feat: add compliance reports page with navigation"
```

---

### Task 5: Guest list enhancement — sortable Last Sign-In column

**Files:**
- Modify: `app/Http/Controllers/GuestUserController.php` (add sort support)
- Modify: `resources/js/components/GuestUserTable.vue` (add column + badge)
- Test: `tests/Feature/GuestUserControllerTest.php` (add sort test)

**Step 1: Write the failing test**

Add to `tests/Feature/GuestUserControllerTest.php`:

```php
test('guests can be sorted by last sign-in date', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response(['value' => []]),
    ]);

    $partner = PartnerOrganization::factory()->create();
    $old = GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => now()->subDays(90),
        'display_name' => 'Old Guest',
    ]);
    $recent = GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => now()->subDays(5),
        'display_name' => 'Recent Guest',
    ]);

    $user = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($user)
        ->get(route('guests.index', ['sort' => 'last_sign_in_at', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('guests/Index')
            ->has('guests.data', 2)
            ->where('guests.data.0.display_name', 'Old Guest')
        );
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="guests can be sorted by last sign-in date"`
Expected: FAIL — sorting not applied, order is by created_at

**Step 3: Add sort support to GuestUserController**

Modify `app/Http/Controllers/GuestUserController.php` `index()` method. Replace the fixed `orderByDesc('created_at')` (around line 50) with:

```php
$sortField = $request->input('sort', 'created_at');
$sortDirection = $request->input('direction', 'desc');
$allowedSorts = ['created_at', 'last_sign_in_at', 'display_name', 'email'];

if (! in_array($sortField, $allowedSorts)) {
    $sortField = 'created_at';
}
if (! in_array($sortDirection, ['asc', 'desc'])) {
    $sortDirection = 'desc';
}

$guests = $query->orderBy($sortField, $sortDirection)
    ->paginate(25)
    ->withQueryString();
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter="guests can be sorted by last sign-in date"`
Expected: PASS

**Step 5: Add Last Sign-In column to GuestUserTable.vue**

Modify `resources/js/components/GuestUserTable.vue`:

Add a new table header column "Last Sign-In" (after the existing columns, before Actions). Make it clickable to sort:

```vue
<TableHead>
    <button class="flex items-center gap-1" @click="applyFilters({ sort: 'last_sign_in_at', direction: currentSort === 'last_sign_in_at' && currentDirection === 'asc' ? 'desc' : 'asc' })">
        Last Sign-In
        <ArrowUpDown class="h-4 w-4" />
    </button>
</TableHead>
```

Add the cell with inactive badge:

```vue
<TableCell>
    <div class="flex items-center gap-2">
        <span v-if="guest.last_sign_in_at">{{ formatDate(guest.last_sign_in_at) }}</span>
        <span v-else class="text-muted-foreground">Never</span>
        <Badge :variant="inactiveBadgeVariant(guest.last_sign_in_at)">
            {{ daysInactiveLabel(guest.last_sign_in_at) }}
        </Badge>
    </div>
</TableCell>
```

Add helper functions:

```typescript
function daysInactiveLabel(lastSignIn: string | null): string {
    if (!lastSignIn) return 'Never';
    const days = Math.floor((Date.now() - new Date(lastSignIn).getTime()) / 86400000);
    return `${days}d`;
}

function inactiveBadgeVariant(lastSignIn: string | null): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (!lastSignIn) return 'destructive';
    const days = Math.floor((Date.now() - new Date(lastSignIn).getTime()) / 86400000);
    if (days >= 90) return 'destructive';
    if (days >= 60) return 'secondary';
    if (days >= 30) return 'outline';
    return 'default';
}
```

**Step 6: Run type check and tests**

Run: `npm run types:check && php artisan test --filter=GuestUserControllerTest`
Expected: PASS

**Step 7: Commit**

```bash
git add app/Http/Controllers/GuestUserController.php resources/js/components/GuestUserTable.vue tests/Feature/GuestUserControllerTest.php
git commit -m "feat: add sortable Last Sign-In column to guest list with inactive badges

Closes #7"
```

---

### Task 6: Final integration and lint

**Files:**
- All modified files

**Step 1: Run full CI check**

Run: `composer run ci:check`
Expected: PASS — linting, formatting, types, tests all green

**Step 2: Fix any lint/format issues**

Run: `composer run lint && npm run lint && npm run format`

**Step 3: Run full test suite**

Run: `php artisan test`
Expected: All tests pass including new ComplianceReportTest

**Step 4: Commit any lint fixes**

```bash
git add -A
git commit -m "style: fix lint and formatting issues"
```

**Step 5: Final commit — close issues**

If not already done in prior commits, ensure commit messages reference:
- `Closes #9` (Compliance reporting dashboard)
- `Closes #7` (Guest sign-in activity monitoring)
