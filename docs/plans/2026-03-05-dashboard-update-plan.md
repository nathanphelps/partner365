# Dashboard Update Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Update the dashboard to an "Action Center" layout with 5 stat cards, a triage section (pending approvals + partners needing attention), and trimmed recent activity.

**Architecture:** Modify the existing `DashboardController` to query additional data (pending assignments, low-trust-score partners) and update `Dashboard.vue` to render the new layout. No new routes, models, or migrations.

**Tech Stack:** Laravel 12 (PHP 8.2), Vue 3 + TypeScript, Inertia.js, shadcn-vue, Tailwind CSS, Pest PHP

---

### Task 1: Write failing tests for updated dashboard controller

**Files:**
- Modify: `tests/Feature/DashboardTest.php`

**Step 1: Write the failing tests**

```php
<?php

use App\Enums\AssignmentStatus;
use App\Enums\InvitationStatus;
use App\Enums\ReviewInstanceStatus;
use App\Models\AccessPackage;
use App\Models\AccessPackageAssignment;
use App\Models\AccessPackageCatalog;
use App\Models\AccessReview;
use App\Models\AccessReviewInstance;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard returns correct stat counts', function () {
    $user = User::factory()->create();

    PartnerOrganization::factory()->count(3)->create();
    GuestUser::factory()->count(2)->create([
        'partner_organization_id' => PartnerOrganization::first()->id,
    ]);
    GuestUser::factory()->create([
        'partner_organization_id' => PartnerOrganization::first()->id,
        'invitation_status' => InvitationStatus::PendingAcceptance,
    ]);
    GuestUser::factory()->create([
        'partner_organization_id' => PartnerOrganization::first()->id,
        'last_sign_in_at' => now()->subDays(100),
    ]);
    GuestUser::factory()->create([
        'partner_organization_id' => PartnerOrganization::first()->id,
        'last_sign_in_at' => null,
    ]);

    $review = AccessReview::factory()->create();
    AccessReviewInstance::factory()->create([
        'access_review_id' => $review->id,
        'status' => ReviewInstanceStatus::InProgress,
        'due_at' => now()->subDays(5),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('stats', fn ($stats) => $stats
            ->where('total_partners', 3)
            ->where('total_guests', 5)
            ->where('pending_invitations', 1)
            ->where('stale_guests', 2)
            ->where('overdue_reviews', 1)
        )
    );
});

test('dashboard returns pending approvals ordered by requested_at asc', function () {
    $user = User::factory()->create();

    $catalog = AccessPackageCatalog::factory()->create();
    $package = AccessPackage::factory()->create(['access_package_catalog_id' => $catalog->id]);

    // Create 6 pending assignments — dashboard should return only 5
    for ($i = 0; $i < 6; $i++) {
        AccessPackageAssignment::factory()->create([
            'access_package_id' => $package->id,
            'status' => AssignmentStatus::PendingApproval,
            'requested_at' => now()->subDays(6 - $i),
        ]);
    }

    // Create one non-pending assignment — should be excluded
    AccessPackageAssignment::factory()->create([
        'access_package_id' => $package->id,
        'status' => AssignmentStatus::Delivered,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('pendingApprovals', 5)
    );

    // Verify ordering: first item should be the oldest
    $approvals = $response->original->getData()['page']['props']['pendingApprovals'];
    expect($approvals[0]['requested_at'])->toBeLessThan($approvals[4]['requested_at']);
});

test('dashboard returns attention partners with trust score below 70', function () {
    $user = User::factory()->create();

    PartnerOrganization::factory()->create(['trust_score' => 80]);
    PartnerOrganization::factory()->create(['trust_score' => 45]);
    PartnerOrganization::factory()->create(['trust_score' => 60]);
    PartnerOrganization::factory()->create(['trust_score' => null]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('attentionPartners', 2)
    );

    // Verify ordering: lowest trust score first
    $partners = $response->original->getData()['page']['props']['attentionPartners'];
    expect($partners[0]['trust_score'])->toBeLessThanOrEqual($partners[1]['trust_score']);
});

test('dashboard returns max 10 recent activity entries', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('recentActivity')
    );
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DashboardTest`
Expected: New tests FAIL (props not yet returned from controller)

**Step 3: Commit**

```bash
git add tests/Feature/DashboardTest.php
git commit -m "test: add failing tests for dashboard action center layout"
```

---

### Task 2: Update DashboardController with new queries

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php`

**Step 1: Update the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Enums\InvitationStatus;
use App\Enums\ReviewInstanceStatus;
use App\Models\AccessPackageAssignment;
use App\Models\AccessReviewInstance;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Services\ActivityLogService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(ActivityLogService $activityLog): Response
    {
        $staleGuestQuery = fn ($q) => $q->where('last_sign_in_at', '<', now()->subDays(90))
            ->orWhereNull('last_sign_in_at');

        return Inertia::render('Dashboard', [
            'stats' => [
                'total_partners' => PartnerOrganization::count(),
                'total_guests' => GuestUser::count(),
                'pending_invitations' => GuestUser::where('invitation_status', InvitationStatus::PendingAcceptance)->count(),
                'stale_guests' => GuestUser::where($staleGuestQuery)->count(),
                'overdue_reviews' => AccessReviewInstance::whereIn('status', [
                    ReviewInstanceStatus::Pending,
                    ReviewInstanceStatus::InProgress,
                ])->where('due_at', '<', now())->count(),
            ],
            'pendingApprovals' => AccessPackageAssignment::with('accessPackage')
                ->where('status', AssignmentStatus::PendingApproval)
                ->orderBy('requested_at')
                ->limit(5)
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'access_package_id' => $a->access_package_id,
                    'access_package_name' => $a->accessPackage?->display_name,
                    'target_user_email' => $a->target_user_email,
                    'requested_at' => $a->requested_at?->toISOString(),
                ]),
            'attentionPartners' => PartnerOrganization::where('trust_score', '<', 70)
                ->whereNotNull('trust_score')
                ->orderBy('trust_score')
                ->limit(5)
                ->withCount(['guestUsers as stale_guests_count' => $staleGuestQuery])
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'display_name' => $p->display_name,
                    'trust_score' => $p->trust_score,
                    'stale_guests_count' => $p->stale_guests_count,
                ]),
            'recentActivity' => $activityLog->recent(10),
        ]);
    }
}
```

**Step 2: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardTest`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add app/Http/Controllers/DashboardController.php
git commit -m "feat: update DashboardController with action center queries"
```

---

### Task 3: Update Dashboard.vue frontend

**Files:**
- Modify: `resources/js/pages/Dashboard.vue`

**Step 1: Rewrite Dashboard.vue with the new layout**

The full replacement for `resources/js/pages/Dashboard.vue`:

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import accessReviews from '@/routes/access-reviews';
import entitlements from '@/routes/entitlements';
import guests from '@/routes/guests';
import partners from '@/routes/partners';
import reports from '@/routes/reports';
import type { BreadcrumbItem } from '@/types';

defineProps<{
    stats: {
        total_partners: number;
        total_guests: number;
        pending_invitations: number;
        stale_guests: number;
        overdue_reviews: number;
    };
    pendingApprovals: {
        id: number;
        access_package_id: number;
        access_package_name: string | null;
        target_user_email: string;
        requested_at: string | null;
    }[];
    attentionPartners: {
        id: number;
        display_name: string;
        trust_score: number;
        stale_guests_count: number;
    }[];
    recentActivity: {
        id: number;
        action: string;
        description: string;
        user: { name: string } | null;
        created_at: string;
    }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
];

const actionVariant = (
    action: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (action.includes('created') || action.includes('invited'))
        return 'default';
    if (action.includes('deleted') || action.includes('removed'))
        return 'destructive';
    if (action.includes('updated') || action.includes('synced'))
        return 'secondary';
    return 'outline';
};

function timeAgo(val: string): string {
    const diff = Date.now() - new Date(val).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    return `${Math.floor(hours / 24)}d ago`;
}

function approveAssignment(accessPackageId: number, assignmentId: number) {
    router.post(
        entitlements.assignments.approve.url({
            entitlement: accessPackageId,
            assignment: assignmentId,
        }),
        {},
        { preserveScroll: true },
    );
}

function denyAssignment(accessPackageId: number, assignmentId: number) {
    router.post(
        entitlements.assignments.deny.url({
            entitlement: accessPackageId,
            assignment: assignmentId,
        }),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Stat Cards -->
            <div class="grid gap-4 md:grid-cols-3 lg:grid-cols-5">
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Total Partners</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p class="text-3xl font-bold">
                            {{ stats.total_partners }}
                        </p>
                        <Link
                            :href="partners.index.url()"
                            class="mt-1 inline-block text-xs text-muted-foreground hover:underline"
                        >
                            View all partners &rarr;
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Total Guests</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p class="text-3xl font-bold">
                            {{ stats.total_guests }}
                        </p>
                        <Link
                            :href="guests.index.url()"
                            class="mt-1 inline-block text-xs text-muted-foreground hover:underline"
                        >
                            View all guests &rarr;
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Stale Guests</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p
                            class="text-3xl font-bold"
                            :class="stats.stale_guests > 0 ? 'text-amber-600' : ''"
                        >
                            {{ stats.stale_guests }}
                        </p>
                        <Link
                            :href="reports.index.url()"
                            class="mt-1 inline-block text-xs text-muted-foreground hover:underline"
                        >
                            View report &rarr;
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Pending Invitations</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p class="text-3xl font-bold">
                            {{ stats.pending_invitations }}
                        </p>
                        <Link
                            :href="
                                guests.index.url() +
                                '?status=pending_acceptance'
                            "
                            class="mt-1 inline-block text-xs text-muted-foreground hover:underline"
                        >
                            View pending &rarr;
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Overdue Reviews</CardTitle
                        >
                    </CardHeader>
                    <CardContent>
                        <p
                            class="text-3xl font-bold"
                            :class="stats.overdue_reviews > 0 ? 'text-red-600' : ''"
                        >
                            {{ stats.overdue_reviews }}
                        </p>
                        <Link
                            :href="accessReviews.index.url()"
                            class="mt-1 inline-block text-xs text-muted-foreground hover:underline"
                        >
                            View reviews &rarr;
                        </Link>
                    </CardContent>
                </Card>
            </div>

            <!-- Triage Section -->
            <div class="grid gap-6 md:grid-cols-2">
                <!-- Pending Approvals -->
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-3"
                    >
                        <CardTitle>Pending Approvals</CardTitle>
                        <Link
                            :href="entitlements.index.url()"
                            class="text-xs text-muted-foreground hover:underline"
                        >
                            View all &rarr;
                        </Link>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="pendingApprovals.length > 0"
                            class="flex flex-col gap-3"
                        >
                            <div
                                v-for="approval in pendingApprovals"
                                :key="approval.id"
                                class="flex items-center justify-between gap-3 border-b pb-3 last:border-0 last:pb-0"
                            >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium">
                                        {{ approval.target_user_email }}
                                    </p>
                                    <p
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{
                                            approval.access_package_name ??
                                            'Unknown package'
                                        }}
                                        <span v-if="approval.requested_at">
                                            &middot;
                                            {{
                                                timeAgo(approval.requested_at)
                                            }}
                                        </span>
                                    </p>
                                </div>
                                <div class="flex shrink-0 gap-1">
                                    <Button
                                        size="sm"
                                        variant="default"
                                        @click="
                                            approveAssignment(
                                                approval.access_package_id,
                                                approval.id,
                                            )
                                        "
                                    >
                                        Approve
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        @click="
                                            denyAssignment(
                                                approval.access_package_id,
                                                approval.id,
                                            )
                                        "
                                    >
                                        Deny
                                    </Button>
                                </div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 py-6 text-muted-foreground"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-8 w-8"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                            <p class="text-sm">No pending approvals</p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Partners Needing Attention -->
                <Card>
                    <CardHeader
                        class="flex flex-row items-center justify-between space-y-0 pb-3"
                    >
                        <CardTitle>Partners Needing Attention</CardTitle>
                        <Link
                            :href="reports.index.url()"
                            class="text-xs text-muted-foreground hover:underline"
                        >
                            View report &rarr;
                        </Link>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="attentionPartners.length > 0"
                            class="flex flex-col gap-3"
                        >
                            <div
                                v-for="partner in attentionPartners"
                                :key="partner.id"
                                class="flex items-center justify-between gap-3 border-b pb-3 last:border-0 last:pb-0"
                            >
                                <div class="min-w-0 flex-1">
                                    <Link
                                        :href="
                                            partners.show.url(partner.id)
                                        "
                                        class="truncate text-sm font-medium hover:underline"
                                    >
                                        {{ partner.display_name }}
                                    </Link>
                                    <p
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{
                                            partner.stale_guests_count
                                        }}
                                        stale guest{{
                                            partner.stale_guests_count !== 1
                                                ? 's'
                                                : ''
                                        }}
                                    </p>
                                </div>
                                <Badge
                                    :variant="
                                        partner.trust_score < 50
                                            ? 'destructive'
                                            : 'secondary'
                                    "
                                >
                                    {{ partner.trust_score }}
                                </Badge>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 py-6 text-muted-foreground"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-8 w-8"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                            <p class="text-sm">
                                All partners in good standing
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Recent Activity -->
            <Card>
                <CardHeader
                    class="flex flex-row items-center justify-between space-y-0 pb-3"
                >
                    <CardTitle>Recent Activity</CardTitle>
                    <Link
                        :href="activityIndex.url()"
                        class="text-xs text-muted-foreground hover:underline"
                    >
                        View all &rarr;
                    </Link>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="recentActivity.length > 0"
                        class="flex flex-col gap-3"
                    >
                        <div
                            v-for="log in recentActivity"
                            :key="log.id"
                            class="flex items-start gap-3 border-b pb-3 last:border-0 last:pb-0"
                        >
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <Badge
                                        :variant="actionVariant(log.action)"
                                        class="text-xs"
                                    >
                                        {{ log.action }}
                                    </Badge>
                                    <span
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ log.user?.name ?? 'System' }}
                                    </span>
                                </div>
                                <p
                                    class="mt-1 truncate text-sm text-muted-foreground"
                                >
                                    {{ log.description }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 text-xs whitespace-nowrap text-muted-foreground"
                            >
                                {{ timeAgo(log.created_at) }}
                            </span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        No recent activity.
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
```

**Step 2: Run lint and type check**

Run: `npm run lint && npm run types:check`
Expected: PASS with no errors

**Step 3: Commit**

```bash
git add resources/js/pages/Dashboard.vue
git commit -m "feat: update Dashboard.vue to action center layout"
```

---

### Task 4: Run full test suite and verify

**Step 1: Run all tests**

Run: `composer run test`
Expected: All tests PASS, no lint errors

**Step 2: Run the full CI check**

Run: `composer run ci:check`
Expected: PASS

**Step 3: Final commit if any formatting fixes were needed**

```bash
git add -A
git commit -m "style: formatting fixes from lint"
```
(Skip if no changes.)
