# Guest User Management Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add per-partner and cross-partner guest user management with enable/disable, resend invite, edit, and bulk actions.

**Architecture:** Extend GuestUserService with enable/disable/resend methods. Add update, resend, and bulk endpoints to GuestUserController. Add partner-scoped guest endpoint to PartnerOrganizationController. Build a shared GuestUserTable Vue component used on both the partner detail page and the global guests page. New ActivityAction enum cases for guest_enabled, guest_disabled, guest_updated.

**Tech Stack:** Laravel 12, Pest PHP, Vue 3 + TypeScript, Inertia.js, shadcn-vue (Dialog, DropdownMenu, Checkbox, Badge, Button)

**Design doc:** `docs/plans/2026-03-04-guest-management-design.md`

---

### Task 1: Add New ActivityAction Enum Cases

**Files:**
- Modify: `app/Enums/ActivityAction.php`

**Step 1: Add new enum cases**

Add three new cases to the ActivityAction enum:

```php
case GuestEnabled = 'guest_enabled';
case GuestDisabled = 'guest_disabled';
case GuestUpdated = 'guest_updated';
```

Add these after the existing `GuestRemoved` case on line 11.

**Step 2: Commit**

```bash
git add app/Enums/ActivityAction.php
git commit -m "feat: add guest_enabled, guest_disabled, guest_updated activity actions"
```

---

### Task 2: Add GuestUserService Methods

**Files:**
- Modify: `app/Services/GuestUserService.php`
- Test: `tests/Feature/Services/GuestUserServiceTest.php`

**Step 1: Write failing tests**

Append these tests to `tests/Feature/Services/GuestUserServiceTest.php`:

```php
test('it enables a guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1' => Http::response([], 204),
    ]);

    $service = app(GuestUserService::class);
    $service->enableUser('u1');

    Http::assertSent(fn ($request) =>
        $request->method() === 'PATCH'
        && str_contains($request->url(), '/users/u1')
        && $request->data()['accountEnabled'] === true
    );
});

test('it disables a guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1' => Http::response([], 204),
    ]);

    $service = app(GuestUserService::class);
    $service->disableUser('u1');

    Http::assertSent(fn ($request) =>
        $request->method() === 'PATCH'
        && str_contains($request->url(), '/users/u1')
        && $request->data()['accountEnabled'] === false
    );
});

test('it resends an invitation', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/invitations' => Http::response([
            'id' => 'inv-2',
            'invitedUserEmailAddress' => 'guest@partner.com',
            'status' => 'PendingAcceptance',
            'invitedUser' => ['id' => 'u1'],
        ], 201),
    ]);

    $service = app(GuestUserService::class);
    $result = $service->resendInvitation('guest@partner.com', 'https://myapp.com');

    expect($result['invitedUserEmailAddress'])->toBe('guest@partner.com');
    Http::assertSent(fn ($request) =>
        $request->method() === 'POST'
        && str_contains($request->url(), '/invitations')
    );
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=GuestUserServiceTest`
Expected: 3 new tests FAIL (methods don't exist yet)

**Step 3: Implement the service methods**

Add to `app/Services/GuestUserService.php` before the closing `}`:

```php
public function enableUser(string $userId): array
{
    return $this->graph->patch("/users/{$userId}", [
        'accountEnabled' => true,
    ]);
}

public function disableUser(string $userId): array
{
    return $this->graph->patch("/users/{$userId}", [
        'accountEnabled' => false,
    ]);
}

public function resendInvitation(string $email, string $redirectUrl): array
{
    return $this->graph->post('/invitations', [
        'invitedUserEmailAddress' => $email,
        'inviteRedirectUrl' => $redirectUrl,
        'sendInvitationMessage' => true,
    ]);
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=GuestUserServiceTest`
Expected: All 7 tests PASS

**Step 5: Commit**

```bash
git add app/Services/GuestUserService.php tests/Feature/Services/GuestUserServiceTest.php
git commit -m "feat: add enable, disable, resend methods to GuestUserService"
```

---

### Task 3: Add Form Requests for New Endpoints

**Files:**
- Create: `app/Http/Requests/UpdateGuestRequest.php`
- Create: `app/Http/Requests/BulkGuestActionRequest.php`

**Step 1: Create UpdateGuestRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->canManage();
    }

    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'string', 'max:255'],
            'account_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
```

**Step 2: Create BulkGuestActionRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkGuestActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->input('action') === 'delete') {
            return $this->user()->role->isAdmin();
        }

        return $this->user()->role->canManage();
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['enable', 'disable', 'delete', 'resend'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:guest_users,id'],
        ];
    }
}
```

**Step 3: Commit**

```bash
git add app/Http/Requests/UpdateGuestRequest.php app/Http/Requests/BulkGuestActionRequest.php
git commit -m "feat: add form requests for guest update and bulk actions"
```

---

### Task 4: Add Controller Actions and Routes

**Files:**
- Modify: `app/Http/Controllers/GuestUserController.php`
- Modify: `app/Http/Controllers/PartnerOrganizationController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/GuestUserControllerTest.php`

**Step 1: Write failing tests**

Append to `tests/Feature/GuestUserControllerTest.php`:

```php
test('operators can update a guest user', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $guest = GuestUser::factory()->create(['account_enabled' => true]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/*' => Http::response([], 204),
    ]);

    $this->actingAs($user)
        ->patch(route('guests.update', $guest), [
            'display_name' => 'New Name',
        ])
        ->assertRedirect();

    expect($guest->fresh()->display_name)->toBe('New Name');
});

test('viewers cannot update a guest user', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);
    $guest = GuestUser::factory()->create();

    $this->actingAs($user)
        ->patch(route('guests.update', $guest), ['display_name' => 'New Name'])
        ->assertForbidden();
});

test('operators can resend an invitation', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $guest = GuestUser::factory()->create(['email' => 'guest@partner.com', 'invitation_status' => 'pending_acceptance']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/invitations' => Http::response([
            'id' => 'inv-2',
            'invitedUserEmailAddress' => 'guest@partner.com',
            'status' => 'PendingAcceptance',
            'invitedUser' => ['id' => 'entra-id'],
        ], 201),
    ]);

    $this->actingAs($user)
        ->post(route('guests.resend', $guest))
        ->assertRedirect();
});

test('operators can perform bulk enable', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $guests = GuestUser::factory()->count(3)->create(['account_enabled' => false]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/*' => Http::response([], 204),
    ]);

    $this->actingAs($user)
        ->post(route('guests.bulk'), [
            'action' => 'enable',
            'ids' => $guests->pluck('id')->toArray(),
        ])
        ->assertOk()
        ->assertJsonStructure(['succeeded', 'failed']);
});

test('only admins can bulk delete', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $guests = GuestUser::factory()->count(2)->create();

    $this->actingAs($user)
        ->post(route('guests.bulk'), [
            'action' => 'delete',
            'ids' => $guests->pluck('id')->toArray(),
        ])
        ->assertForbidden();
});

test('admins can view partner guests', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $partner = \App\Models\PartnerOrganization::factory()->create();
    GuestUser::factory()->count(5)->create(['partner_organization_id' => $partner->id]);
    GuestUser::factory()->count(3)->create(); // other partner

    $this->actingAs($user)
        ->get(route('partners.guests', $partner))
        ->assertOk()
        ->assertJsonCount(5, 'data');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=GuestUserControllerTest`
Expected: 6 new tests FAIL (routes/methods don't exist)

**Step 3: Add routes**

In `routes/web.php`, replace line 22:
```php
Route::resource('guests', GuestUserController::class)->except(['edit', 'update']);
```

With:
```php
Route::resource('guests', GuestUserController::class)->except(['edit']);
Route::post('guests/{guest}/resend', [GuestUserController::class, 'resendInvitation'])->name('guests.resend');
Route::post('guests/bulk', [GuestUserController::class, 'bulkAction'])->name('guests.bulk');
```

**Important:** The `guests/bulk` route must come AFTER `Route::resource` but the `resend` route can be anywhere. Actually, `guests/bulk` must be defined BEFORE the resource route to avoid `bulk` being interpreted as a guest ID. Reorder:

```php
Route::post('guests/bulk', [GuestUserController::class, 'bulkAction'])->name('guests.bulk');
Route::resource('guests', GuestUserController::class)->except(['edit']);
Route::post('guests/{guest}/resend', [GuestUserController::class, 'resendInvitation'])->name('guests.resend');
```

Add partner guests route after the `Route::resource('partners', ...)` line:
```php
Route::get('partners/{partner}/guests', [PartnerOrganizationController::class, 'guests'])->name('partners.guests');
```

**Step 4: Add GuestUserController methods**

Add these use statements at the top of `app/Http/Controllers/GuestUserController.php`:

```php
use App\Http\Requests\UpdateGuestRequest;
use App\Http\Requests\BulkGuestActionRequest;
use Illuminate\Http\JsonResponse;
```

Add these methods to the controller:

```php
public function update(UpdateGuestRequest $request, GuestUser $guest): RedirectResponse
{
    $validated = $request->validated();

    if (isset($validated['display_name'])) {
        $this->guestService->updateUser($guest->entra_user_id, [
            'displayName' => $validated['display_name'],
        ]);
    }

    if (isset($validated['account_enabled'])) {
        if ($validated['account_enabled']) {
            $this->guestService->enableUser($guest->entra_user_id);
        } else {
            $this->guestService->disableUser($guest->entra_user_id);
        }

        $action = $validated['account_enabled']
            ? ActivityAction::GuestEnabled
            : ActivityAction::GuestDisabled;
        $this->activityLog->log($request->user(), $action, $guest, ['email' => $guest->email]);
    }

    if (isset($validated['display_name']) && !isset($validated['account_enabled'])) {
        $this->activityLog->log($request->user(), ActivityAction::GuestUpdated, $guest, [
            'display_name' => $validated['display_name'],
        ]);
    }

    $guest->update($validated);

    return redirect()->back()->with('success', 'Guest user updated.');
}

public function resendInvitation(Request $request, GuestUser $guest): RedirectResponse
{
    if (!$request->user()->role->canManage()) {
        abort(403);
    }

    $this->guestService->resendInvitation(
        $guest->email,
        config('app.url'),
    );

    $this->activityLog->log($request->user(), ActivityAction::GuestInvited, $guest, [
        'email' => $guest->email,
        'resend' => true,
    ]);

    return redirect()->back()->with('success', "Invitation resent to {$guest->email}.");
}

public function bulkAction(BulkGuestActionRequest $request): JsonResponse
{
    $validated = $request->validated();
    $guests = GuestUser::whereIn('id', $validated['ids'])->get();
    $succeeded = [];
    $failed = [];

    foreach ($guests as $guest) {
        try {
            match ($validated['action']) {
                'enable' => $this->handleBulkEnable($request->user(), $guest),
                'disable' => $this->handleBulkDisable($request->user(), $guest),
                'delete' => $this->handleBulkDelete($request->user(), $guest),
                'resend' => $this->handleBulkResend($request->user(), $guest),
            };
            $succeeded[] = $guest->id;
        } catch (\Throwable $e) {
            $failed[] = ['id' => $guest->id, 'error' => $e->getMessage()];
        }
    }

    return response()->json(['succeeded' => $succeeded, 'failed' => $failed]);
}

private function handleBulkEnable(User $user, GuestUser $guest): void
{
    $this->guestService->enableUser($guest->entra_user_id);
    $guest->update(['account_enabled' => true]);
    $this->activityLog->log($user, ActivityAction::GuestEnabled, $guest, ['email' => $guest->email]);
}

private function handleBulkDisable(User $user, GuestUser $guest): void
{
    $this->guestService->disableUser($guest->entra_user_id);
    $guest->update(['account_enabled' => false]);
    $this->activityLog->log($user, ActivityAction::GuestDisabled, $guest, ['email' => $guest->email]);
}

private function handleBulkDelete(User $user, GuestUser $guest): void
{
    $this->guestService->deleteUser($guest->entra_user_id);
    $this->activityLog->log($user, ActivityAction::GuestRemoved, $guest, ['email' => $guest->email]);
    $guest->delete();
}

private function handleBulkResend(User $user, GuestUser $guest): void
{
    $this->guestService->resendInvitation($guest->email, config('app.url'));
    $this->activityLog->log($user, ActivityAction::GuestInvited, $guest, ['email' => $guest->email, 'resend' => true]);
}
```

Add the `User` import at top:
```php
use App\Models\User;
```

**Step 5: Add PartnerOrganizationController guests method**

Add to `app/Http/Controllers/PartnerOrganizationController.php`:

```php
use Illuminate\Http\JsonResponse;  // already imported, check first
```

Add method:

```php
public function guests(Request $request, PartnerOrganization $partner): JsonResponse
{
    $query = $partner->guestUsers()->with('invitedBy');

    if ($search = $request->input('search')) {
        $query->where(function ($q) use ($search) {
            $q->where('display_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        });
    }

    if ($status = $request->input('status')) {
        $query->where('invitation_status', $status);
    }

    if ($request->has('account_enabled')) {
        $query->where('account_enabled', $request->boolean('account_enabled'));
    }

    return response()->json(
        $query->orderByDesc('created_at')->paginate(25)->withQueryString()
    );
}
```

**Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=GuestUserControllerTest`
Expected: All 11 tests PASS

**Step 7: Commit**

```bash
git add app/Http/Controllers/GuestUserController.php app/Http/Controllers/PartnerOrganizationController.php routes/web.php tests/Feature/GuestUserControllerTest.php
git commit -m "feat: add guest update, resend, bulk action endpoints and partner guests route"
```

---

### Task 5: Update GuestUser Index Controller to Pass `account_enabled` Filter and `canManage` Prop

**Files:**
- Modify: `app/Http/Controllers/GuestUserController.php` (index method)

**Step 1: Update the index method**

In the `index` method of `GuestUserController.php`, add after the status filter block (line ~40):

```php
if ($request->has('account_enabled')) {
    $query->where('account_enabled', $request->boolean('account_enabled'));
}
```

Update the `filters` key to include the new filters:
```php
'filters' => $request->only(['search', 'partner_id', 'status', 'account_enabled']),
```

Add `canManage` prop to the Inertia render:
```php
'canManage' => $request->user()->role->canManage(),
```

**Step 2: Update partner Show controller to pass paginated guests and canManage**

In `PartnerOrganizationController::show()`, change the guest loading from eager load to a separate paginated query:

```php
public function show(Request $request, PartnerOrganization $partner): Response
{
    $partner->load('owner');

    $guests = $partner->guestUsers()
        ->with('invitedBy')
        ->orderByDesc('created_at')
        ->paginate(25, ['*'], 'guests_page')
        ->withQueryString();

    return Inertia::render('partners/Show', [
        'partner' => $partner,
        'guests' => $guests,
        'activity' => $this->activityLog->forSubject($partner),
        'canManage' => $request->user()->role->canManage(),
    ]);
}
```

Note: The method signature changes to add `Request $request`.

**Step 3: Commit**

```bash
git add app/Http/Controllers/GuestUserController.php app/Http/Controllers/PartnerOrganizationController.php
git commit -m "feat: add account_enabled filter and canManage prop to guest views"
```

---

### Task 6: Update TypeScript Types

**Files:**
- Modify: `resources/js/types/guest.ts`

**Step 1: Add `account_enabled` field to GuestUser type**

Update `resources/js/types/guest.ts` to add the `account_enabled` field:

```typescript
export type GuestUser = {
    id: number;
    entra_user_id: string;
    email: string;
    display_name: string;
    user_principal_name: string | null;
    partner_organization_id: number | null;
    partner_organization?: { id: number; display_name: string };
    invited_by_user_id: number | null;
    invited_by?: { id: number; name: string };
    invitation_status: 'pending_acceptance' | 'accepted' | 'failed';
    account_enabled: boolean;
    last_sign_in_at: string | null;
    last_synced_at: string | null;
    created_at: string;
};
```

**Step 2: Commit**

```bash
git add resources/js/types/guest.ts
git commit -m "feat: add account_enabled to GuestUser TypeScript type"
```

---

### Task 7: Generate Wayfinder Routes

**Files:**
- Routes are auto-generated

**Step 1: Run Wayfinder to generate TypeScript route helpers for the new routes**

Run: `php artisan wayfinder:generate`

This generates route helpers for `guests.update`, `guests.resend`, `guests.bulk`, and `partners.guests`.

**Step 2: Verify new route helpers exist**

Check that `resources/js/routes/guests.ts` now includes `update`, `resend`, and `bulk` routes. Check `resources/js/routes/partners.ts` includes `guests`.

**Step 3: Commit**

```bash
git add resources/js/routes/
git commit -m "chore: regenerate Wayfinder routes for new guest endpoints"
```

---

### Task 8: Build GuestUserTable Component

**Files:**
- Create: `resources/js/components/GuestUserTable.vue`

**Step 1: Create the shared component**

Create `resources/js/components/GuestUserTable.vue`:

```vue
<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import guestRoutes from '@/routes/guests';
import partnerRoutes from '@/routes/partners';
import type { GuestUser, Paginated } from '@/types/partner';

const props = defineProps<{
    guests: Paginated<GuestUser>;
    partnerId?: number;
    canManage: boolean;
    filters?: {
        search?: string;
        status?: string;
        account_enabled?: string;
        partner_id?: string;
    };
    partners?: { id: number; display_name: string }[];
}>();

// Selection state
const selectedIds = ref<Set<number>>(new Set());
const allSelected = computed(() =>
    props.guests.data.length > 0 && props.guests.data.every((g) => selectedIds.value.has(g.id)),
);

function toggleAll(checked: boolean) {
    if (checked) {
        props.guests.data.forEach((g) => selectedIds.value.add(g.id));
    } else {
        selectedIds.value.clear();
    }
}

function toggleOne(id: number, checked: boolean) {
    if (checked) {
        selectedIds.value.add(id);
    } else {
        selectedIds.value.delete(id);
    }
}

// Filters
const search = ref(props.filters?.search ?? '');
const statusFilter = ref(props.filters?.status ?? '');
const enabledFilter = ref(props.filters?.account_enabled ?? '');
const partnerFilter = ref(props.filters?.partner_id ?? '');

let searchTimer: ReturnType<typeof setTimeout>;

function applyFilters(overrides: Record<string, string> = {}) {
    const params: Record<string, string> = {
        search: search.value,
        status: statusFilter.value,
        account_enabled: enabledFilter.value,
        ...(!props.partnerId ? { partner_id: partnerFilter.value } : {}),
        ...overrides,
    };

    // Remove empty values
    Object.keys(params).forEach((k) => {
        if (!params[k]) delete params[k];
    });

    const url = props.partnerId
        ? partnerRoutes.show.url(props.partnerId)
        : guestRoutes.index.url();

    router.get(url, params, { preserveState: true, replace: true });
}

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 400);
}

function onFilterChange() {
    applyFilters();
}

// Actions
const actionLoading = ref(false);

function toggleEnabled(guest: GuestUser) {
    actionLoading.value = true;
    router.patch(
        guestRoutes.update.url(guest.id),
        { account_enabled: !guest.account_enabled },
        {
            preserveScroll: true,
            onFinish: () => { actionLoading.value = false; },
        },
    );
}

function resendInvite(guest: GuestUser) {
    actionLoading.value = true;
    router.post(
        guestRoutes.resend.url(guest.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => { actionLoading.value = false; },
        },
    );
}

function deleteGuest(guest: GuestUser) {
    confirmAction.value = {
        title: 'Delete Guest User',
        description: `Are you sure you want to delete ${guest.display_name}? This cannot be undone.`,
        variant: 'destructive',
        onConfirm: () => {
            router.delete(guestRoutes.destroy.url(guest.id), { preserveScroll: true });
            confirmAction.value = null;
        },
    };
}

// Edit modal
const editingGuest = ref<GuestUser | null>(null);
const editDisplayName = ref('');

function openEdit(guest: GuestUser) {
    editingGuest.value = guest;
    editDisplayName.value = guest.display_name;
}

function saveEdit() {
    if (!editingGuest.value) return;
    router.patch(
        guestRoutes.update.url(editingGuest.value.id),
        { display_name: editDisplayName.value },
        {
            preserveScroll: true,
            onSuccess: () => { editingGuest.value = null; },
        },
    );
}

// Confirm dialog
const confirmAction = ref<{
    title: string;
    description: string;
    variant: 'default' | 'destructive';
    onConfirm: () => void;
} | null>(null);

// Bulk actions
const bulkLoading = ref(false);
const bulkResult = ref<{ succeeded: number[]; failed: { id: number; error: string }[] } | null>(null);

async function executeBulkAction(action: 'enable' | 'disable' | 'delete' | 'resend') {
    const ids = Array.from(selectedIds.value);
    const labels: Record<string, string> = {
        enable: 'enable', disable: 'disable', delete: 'delete', resend: 'resend invitations for',
    };

    confirmAction.value = {
        title: `Bulk ${action}`,
        description: `Are you sure you want to ${labels[action]} ${ids.length} guest user(s)?`,
        variant: action === 'delete' || action === 'disable' ? 'destructive' : 'default',
        onConfirm: async () => {
            confirmAction.value = null;
            bulkLoading.value = true;
            try {
                const response = await fetch(guestRoutes.bulk.url(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ action, ids }),
                });
                const data = await response.json();
                bulkResult.value = data;
                selectedIds.value.clear();
                router.reload({ preserveScroll: true });
            } finally {
                bulkLoading.value = false;
            }
        },
    };
}

// Helpers
function statusVariant(status: string): 'default' | 'destructive' | 'outline' {
    if (status === 'accepted') return 'default';
    if (status === 'failed') return 'destructive';
    return 'outline';
}

function statusLabel(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(val: string | null): string {
    if (!val) return '—';
    return new Date(val).toLocaleDateString();
}
</script>

<template>
    <!-- Bulk result toast -->
    <div
        v-if="bulkResult"
        class="mb-4 rounded-lg border p-3 text-sm"
        :class="bulkResult.failed.length ? 'border-yellow-500 bg-yellow-50 dark:bg-yellow-950' : 'border-green-500 bg-green-50 dark:bg-green-950'"
    >
        <div class="flex items-center justify-between">
            <span>
                {{ bulkResult.succeeded.length }} succeeded<span v-if="bulkResult.failed.length">, {{ bulkResult.failed.length }} failed</span>.
            </span>
            <Button variant="ghost" size="sm" @click="bulkResult = null">Dismiss</Button>
        </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap gap-3 mb-4">
        <Input
            v-model="search"
            placeholder="Search by name or email..."
            class="max-w-sm"
            @input="onSearchInput"
        />
        <select
            v-model="statusFilter"
            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
            @change="onFilterChange"
        >
            <option value="">All Statuses</option>
            <option value="pending_acceptance">Pending Acceptance</option>
            <option value="accepted">Accepted</option>
            <option value="failed">Failed</option>
        </select>
        <select
            v-model="enabledFilter"
            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
            @change="onFilterChange"
        >
            <option value="">All Accounts</option>
            <option value="1">Enabled</option>
            <option value="0">Disabled</option>
        </select>
        <select
            v-if="!partnerId && partners"
            v-model="partnerFilter"
            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
            @change="onFilterChange"
        >
            <option value="">All Partners</option>
            <option v-for="p in partners" :key="p.id" :value="String(p.id)">{{ p.display_name }}</option>
        </select>
    </div>

    <!-- Bulk action bar -->
    <div v-if="selectedIds.size > 0" class="flex items-center gap-3 mb-4 rounded-lg border bg-muted/50 p-3">
        <span class="text-sm font-medium">{{ selectedIds.size }} selected</span>
        <Button size="sm" variant="outline" :disabled="bulkLoading" @click="executeBulkAction('enable')">Enable</Button>
        <Button size="sm" variant="outline" :disabled="bulkLoading" @click="executeBulkAction('disable')">Disable</Button>
        <Button size="sm" variant="outline" :disabled="bulkLoading" @click="executeBulkAction('resend')">Resend</Button>
        <Button size="sm" variant="destructive" :disabled="bulkLoading" @click="executeBulkAction('delete')">Delete</Button>
        <Button size="sm" variant="ghost" @click="selectedIds.clear()">Clear</Button>
    </div>

    <!-- Table -->
    <div class="rounded-lg border bg-card">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b bg-muted/50">
                    <th v-if="canManage" class="w-10 px-4 py-3">
                        <Checkbox :checked="allSelected" @update:checked="toggleAll" />
                    </th>
                    <th class="px-4 py-3 text-left font-medium text-muted-foreground">Name</th>
                    <th class="px-4 py-3 text-left font-medium text-muted-foreground">Email</th>
                    <th v-if="!partnerId" class="px-4 py-3 text-left font-medium text-muted-foreground">Partner Org</th>
                    <th class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                    <th class="px-4 py-3 text-left font-medium text-muted-foreground">Enabled</th>
                    <th class="px-4 py-3 text-left font-medium text-muted-foreground">Last Sign In</th>
                    <th v-if="canManage" class="px-4 py-3 text-right font-medium text-muted-foreground">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="guest in guests.data"
                    :key="guest.id"
                    class="border-b last:border-0 hover:bg-muted/30 transition-colors"
                >
                    <td v-if="canManage" class="w-10 px-4 py-3">
                        <Checkbox
                            :checked="selectedIds.has(guest.id)"
                            @update:checked="(val: boolean) => toggleOne(guest.id, val)"
                        />
                    </td>
                    <td class="px-4 py-3">
                        <Link :href="guestRoutes.show.url(guest.id)" class="font-medium text-foreground hover:underline">
                            {{ guest.display_name }}
                        </Link>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">{{ guest.email }}</td>
                    <td v-if="!partnerId" class="px-4 py-3">
                        <Link
                            v-if="guest.partner_organization"
                            :href="partnerRoutes.show.url(guest.partner_organization.id)"
                            class="hover:underline text-sm"
                        >
                            {{ guest.partner_organization.display_name }}
                        </Link>
                        <span v-else class="text-muted-foreground">—</span>
                    </td>
                    <td class="px-4 py-3">
                        <Badge :variant="statusVariant(guest.invitation_status)">
                            {{ statusLabel(guest.invitation_status) }}
                        </Badge>
                    </td>
                    <td class="px-4 py-3">
                        <Badge :variant="guest.account_enabled ? 'default' : 'outline'">
                            {{ guest.account_enabled ? 'Yes' : 'No' }}
                        </Badge>
                    </td>
                    <td class="px-4 py-3 text-muted-foreground">{{ formatDate(guest.last_sign_in_at) }}</td>
                    <td v-if="canManage" class="px-4 py-3 text-right">
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button variant="ghost" size="sm">...</Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem @click="openEdit(guest)">Edit</DropdownMenuItem>
                                <DropdownMenuItem @click="toggleEnabled(guest)">
                                    {{ guest.account_enabled ? 'Disable' : 'Enable' }}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="guest.invitation_status === 'pending_acceptance'"
                                    @click="resendInvite(guest)"
                                >
                                    Resend Invite
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem class="text-destructive" @click="deleteGuest(guest)">
                                    Delete
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </td>
                </tr>
                <tr v-if="guests.data.length === 0">
                    <td :colspan="canManage ? 8 : 6" class="px-4 py-8 text-center text-muted-foreground">
                        No guest users found.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div v-if="guests.last_page > 1" class="flex items-center justify-between mt-4">
        <p class="text-sm text-muted-foreground">
            Showing {{ guests.data.length }} of {{ guests.total }} guests
        </p>
        <div class="flex gap-1">
            <template v-for="link in guests.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    :class="[
                        'inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm transition-colors',
                        link.active ? 'bg-primary text-primary-foreground font-medium' : 'border hover:bg-muted',
                    ]"
                    v-html="link.label"
                />
                <span
                    v-else
                    class="inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm text-muted-foreground opacity-50"
                    v-html="link.label"
                />
            </template>
        </div>
    </div>

    <!-- Edit modal -->
    <Dialog :open="!!editingGuest" @update:open="(val) => { if (!val) editingGuest = null; }">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Edit Guest User</DialogTitle>
                <DialogDescription>Update the display name for this guest user.</DialogDescription>
            </DialogHeader>
            <div class="py-4">
                <Input v-model="editDisplayName" placeholder="Display name" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="editingGuest = null">Cancel</Button>
                <Button @click="saveEdit">Save</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Confirm dialog -->
    <Dialog :open="!!confirmAction" @update:open="(val) => { if (!val) confirmAction = null; }">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ confirmAction?.title }}</DialogTitle>
                <DialogDescription>{{ confirmAction?.description }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="confirmAction = null">Cancel</Button>
                <Button
                    :variant="confirmAction?.variant === 'destructive' ? 'destructive' : 'default'"
                    @click="confirmAction?.onConfirm()"
                >
                    Confirm
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/components/GuestUserTable.vue
git commit -m "feat: add shared GuestUserTable component with bulk actions, filters, edit modal"
```

---

### Task 9: Update Global Guests Page to Use GuestUserTable

**Files:**
- Modify: `resources/js/pages/guests/Index.vue`

**Step 1: Replace the current implementation**

Replace the entire contents of `resources/js/pages/guests/Index.vue`:

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import GuestUserTable from '@/components/GuestUserTable.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import guestRoutes from '@/routes/guests';
import type { BreadcrumbItem } from '@/types';
import type { GuestUser, Paginated } from '@/types/partner';

const props = defineProps<{
    guests: Paginated<GuestUser>;
    filters?: { search?: string; status?: string; account_enabled?: string; partner_id?: string };
    partners?: { id: number; display_name: string }[];
    canManage: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Guest Users', href: guestRoutes.index.url() },
];
</script>

<template>
    <Head title="Guest Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Guest Users</h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        Manage external guest users in your M365 tenant.
                    </p>
                </div>
                <Link v-if="canManage" :href="guestRoutes.create.url()">
                    <Button>Invite Guest</Button>
                </Link>
            </div>

            <GuestUserTable
                :guests="guests"
                :can-manage="canManage"
                :filters="filters"
                :partners="partners"
            />
        </div>
    </AppLayout>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/guests/Index.vue
git commit -m "feat: replace guests index with shared GuestUserTable component"
```

---

### Task 10: Update Partner Detail Page to Use GuestUserTable

**Files:**
- Modify: `resources/js/pages/partners/Show.vue`

**Step 1: Update the component**

In `resources/js/pages/partners/Show.vue`:

1. Add import at top of `<script setup>`:
```typescript
import GuestUserTable from '@/components/GuestUserTable.vue';
import type { Paginated } from '@/types/partner';
```

2. Update props to change `guests` from `GuestUser[]` to `Paginated<GuestUser>` and add `canManage`:
```typescript
const props = defineProps<{
    partner: PartnerOrganization;
    guests: Paginated<GuestUser>;
    canManage: boolean;
}>();
```

3. Add `canManage` computed for the isAdmin check (already using `usePage`, keep that):
```typescript
const canManage = computed(() => {
    const auth = page.props.auth as { user?: { role?: string } };
    return auth?.user?.role === 'admin' || auth?.user?.role === 'operator';
});
```

Actually, since we're passing `canManage` as a prop now, remove the computed and use the prop directly.

4. Replace the Guest Users Card section (lines ~202-245) with:

```html
<!-- Guest Users -->
<Card>
    <CardHeader>
        <CardTitle>Guest Users ({{ guests.total }})</CardTitle>
    </CardHeader>
    <CardContent>
        <GuestUserTable
            :guests="guests"
            :partner-id="partner.id"
            :can-manage="canManage"
        />
    </CardContent>
</Card>
```

**Step 2: Commit**

```bash
git add resources/js/pages/partners/Show.vue
git commit -m "feat: embed GuestUserTable in partner detail page"
```

---

### Task 11: Run Full Test Suite and Lint

**Step 1: Run full CI check**

Run: `composer run ci:check`
Expected: All checks pass (lint, format, types, tests)

**Step 2: Fix any issues found**

If linting fails, run `composer run lint` and `npm run format` to auto-fix.

**Step 3: Final commit if fixes needed**

```bash
git add -A
git commit -m "chore: fix lint and formatting"
```

---

### Task 12: Manual Smoke Test Checklist

Verify these scenarios work in the browser:

1. **Global guests page** (`/guests`): Table renders with checkboxes, filters, actions dropdown
2. **Partner detail page** (`/partners/{id}`): Guest Users section shows paginated table
3. **Edit action**: Click Edit on a guest, modal opens, save updates name
4. **Enable/Disable**: Toggle via actions dropdown, badge updates
5. **Resend invite**: Shows for pending guests only
6. **Bulk select**: Select multiple, bulk bar appears with action buttons
7. **Bulk action**: Execute bulk enable, see success toast
8. **Filters**: Search, status, enabled, partner filters all work
9. **Viewer role**: No checkboxes, no action buttons, no Invite button
10. **Pagination**: Navigate pages, filters persist
