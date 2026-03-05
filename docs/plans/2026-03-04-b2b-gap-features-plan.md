# B2B Gap Features Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add three new features: External Collaboration Settings (admin page), B2B Direct Connect inbound/outbound management (partner detail), and Tenant Restrictions v2 (partner detail).

**Architecture:** Each feature follows the existing pattern: Service → Controller → FormRequest → Inertia Vue page. Features 2 and 3 extend existing partner infrastructure with new database columns, service methods, and Vue card components on the Show page. Feature 1 is a new admin page with its own service, controller, and route.

**Tech Stack:** Laravel 12, Pest PHP, Vue 3 + Inertia.js, shadcn-vue, Tailwind CSS, Microsoft Graph API

---

## Task 1: Migration — Direct Connect Inbound/Outbound Split

**Files:**
- Create: `database/migrations/2026_03_04_300000_split_direct_connect_columns.php`
- Modify: `app/Models/PartnerOrganization.php:12-20` (fillable + casts)
- Modify: `resources/js/types/partner.ts:19` (replace direct_connect_enabled)

**Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->boolean('direct_connect_inbound_enabled')->default(false)->after('direct_connect_enabled');
            $table->boolean('direct_connect_outbound_enabled')->default(false)->after('direct_connect_inbound_enabled');
        });

        // Migrate existing data
        DB::table('partner_organizations')
            ->where('direct_connect_enabled', true)
            ->update([
                'direct_connect_inbound_enabled' => true,
                'direct_connect_outbound_enabled' => true,
            ]);

        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->dropColumn('direct_connect_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->boolean('direct_connect_enabled')->default(false)->after('device_trust_enabled');
        });

        DB::table('partner_organizations')
            ->where('direct_connect_inbound_enabled', true)
            ->orWhere('direct_connect_outbound_enabled', true)
            ->update(['direct_connect_enabled' => true]);

        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->dropColumn(['direct_connect_inbound_enabled', 'direct_connect_outbound_enabled']);
        });
    }
};
```

Add `use Illuminate\Support\Facades\DB;` at top.

**Step 2: Update PartnerOrganization model**

In `app/Models/PartnerOrganization.php`, replace `direct_connect_enabled` with `direct_connect_inbound_enabled` and `direct_connect_outbound_enabled` in both `$fillable` and `casts()`.

**Step 3: Update TypeScript type**

In `resources/js/types/partner.ts`, replace:
```typescript
direct_connect_enabled: boolean;
```
with:
```typescript
direct_connect_inbound_enabled: boolean;
direct_connect_outbound_enabled: boolean;
```

**Step 4: Run migration**

Run: `php artisan migrate`
Expected: Migration runs successfully

**Step 5: Commit**

```bash
git add database/migrations/2026_03_04_300000_split_direct_connect_columns.php app/Models/PartnerOrganization.php resources/js/types/partner.ts
git commit -m "refactor: split direct_connect_enabled into inbound/outbound columns"
```

---

## Task 2: Migration — Tenant Restrictions Columns

**Files:**
- Create: `database/migrations/2026_03_04_300001_add_tenant_restrictions_columns.php`
- Modify: `app/Models/PartnerOrganization.php` (add to fillable + casts)
- Modify: `resources/js/types/partner.ts` (add new fields)

**Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->boolean('tenant_restrictions_enabled')->default(false)->after('direct_connect_outbound_enabled');
            $table->json('tenant_restrictions_json')->nullable()->after('tenant_restrictions_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->dropColumn(['tenant_restrictions_enabled', 'tenant_restrictions_json']);
        });
    }
};
```

**Step 2: Update model** — Add `tenant_restrictions_enabled` and `tenant_restrictions_json` to `$fillable`. Add casts: `'tenant_restrictions_enabled' => 'boolean'`, `'tenant_restrictions_json' => 'array'`.

**Step 3: Update TypeScript type** — Add to `PartnerOrganization`:
```typescript
tenant_restrictions_enabled: boolean;
tenant_restrictions_json: Record<string, unknown> | null;
```

**Step 4: Run migration**

Run: `php artisan migrate`
Expected: Migration runs successfully

**Step 5: Commit**

```bash
git add database/migrations/2026_03_04_300001_add_tenant_restrictions_columns.php app/Models/PartnerOrganization.php resources/js/types/partner.ts
git commit -m "feat: add tenant restrictions columns to partner_organizations"
```

---

## Task 3: Update Policy Config, Request Validation, and Controller for Direct Connect Split

**Files:**
- Modify: `resources/js/lib/policy-config.ts:22-29` (update direct connect entry)
- Modify: `app/Http/Requests/UpdatePartnerRequest.php:26-27` (replace validation rule)
- Modify: `app/Http/Requests/StorePartnerRequest.php` (same)
- Modify: `app/Http/Controllers/PartnerOrganizationController.php:190-229` (update buildGraphConfig)
- Modify: `resources/js/pages/partners/Show.vue:52-57` (update policyForm)

**Step 1: Update policy-config.ts**

Replace the single Direct Connect entry with two entries:
```typescript
{
    key: 'direct_connect_inbound_enabled',
    label: 'Direct Connect Inbound',
    description: 'Allow their users to connect to our shared channels.',
    tooltip:
        'When enabled, users from this partner can join your Teams shared channels as external members. Both organizations must enable direct connect for shared channels to work.',
},
{
    key: 'direct_connect_outbound_enabled',
    label: 'Direct Connect Outbound',
    description: 'Allow our users to connect to their shared channels.',
    tooltip:
        'When enabled, your users can join shared channels in this partner\'s Teams environment. Both organizations must enable direct connect for shared channels to work.',
},
```

**Step 2: Update UpdatePartnerRequest.php**

Replace `'direct_connect_enabled' => ['boolean'],` with:
```php
'direct_connect_inbound_enabled' => ['boolean'],
'direct_connect_outbound_enabled' => ['boolean'],
```

**Step 3: Update StorePartnerRequest.php** — Same change.

**Step 4: Update buildGraphConfig in PartnerOrganizationController**

Add after the `b2bCollaborationOutbound` block (~line 227):
```php
if (isset($data['direct_connect_inbound_enabled'])) {
    $accessType = $data['direct_connect_inbound_enabled'] ? 'allowed' : 'blocked';
    $config['b2bDirectConnectInbound'] = [
        'usersAndGroups' => [
            'accessType' => $accessType,
            'targets' => [['target' => 'AllUsers', 'targetType' => 'user']],
        ],
        'applications' => [
            'accessType' => $accessType,
            'targets' => [['target' => 'AllApplications', 'targetType' => 'application']],
        ],
    ];
}

if (isset($data['direct_connect_outbound_enabled'])) {
    $accessType = $data['direct_connect_outbound_enabled'] ? 'allowed' : 'blocked';
    $config['b2bDirectConnectOutbound'] = [
        'usersAndGroups' => [
            'accessType' => $accessType,
            'targets' => [['target' => 'AllUsers', 'targetType' => 'user']],
        ],
        'applications' => [
            'accessType' => $accessType,
            'targets' => [['target' => 'AllApplications', 'targetType' => 'application']],
        ],
    ];
}
```

Remove or update the old `direct_connect_enabled` handling if present.

**Step 5: Update store() method** — Replace `'direct_connect_enabled' => $validated['direct_connect_enabled'] ?? false,` with:
```php
'direct_connect_inbound_enabled' => $validated['direct_connect_inbound_enabled'] ?? false,
'direct_connect_outbound_enabled' => $validated['direct_connect_outbound_enabled'] ?? false,
```

**Step 6: Update Show.vue policyForm** — Replace `direct_connect_enabled` with both new fields:
```typescript
const policyForm = reactive({
    mfa_trust_enabled: props.partner.mfa_trust_enabled,
    device_trust_enabled: props.partner.device_trust_enabled,
    direct_connect_inbound_enabled: props.partner.direct_connect_inbound_enabled,
    direct_connect_outbound_enabled: props.partner.direct_connect_outbound_enabled,
    b2b_inbound_enabled: props.partner.b2b_inbound_enabled,
    b2b_outbound_enabled: props.partner.b2b_outbound_enabled,
});
```

**Step 7: Run type check and lint**

Run: `npm run types:check && npm run lint && composer run lint`
Expected: No errors

**Step 8: Commit**

```bash
git add -A
git commit -m "feat: split direct connect into inbound/outbound toggles"
```

---

## Task 4: Update SyncPartners Command for Direct Connect + Tenant Restrictions

**Files:**
- Modify: `app/Console/Commands/SyncPartners.php:47-58`

**Step 1: Update sync mapping**

In `SyncPartners.php`, update the `updateOrCreate` call. Replace the `direct_connect_enabled` line and add tenant restrictions:

```php
$directConnectInbound = $partner['b2bDirectConnectInbound'] ?? [];
$directConnectOutbound = $partner['b2bDirectConnectOutbound'] ?? [];
$tenantRestrictions = $partner['tenantRestrictions'] ?? null;

PartnerOrganization::updateOrCreate(
    ['tenant_id' => $tenantId],
    [
        'display_name' => $displayName,
        'domain' => $domain,
        'mfa_trust_enabled' => $inboundTrust['isMfaAccepted'] ?? false,
        'device_trust_enabled' => $inboundTrust['isCompliantDeviceAccepted'] ?? false,
        'b2b_inbound_enabled' => ($b2bInbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
        'b2b_outbound_enabled' => ($b2bOutbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
        'direct_connect_inbound_enabled' => ($directConnectInbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
        'direct_connect_outbound_enabled' => ($directConnectOutbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
        'tenant_restrictions_enabled' => $tenantRestrictions !== null,
        'tenant_restrictions_json' => $tenantRestrictions,
        'raw_policy_json' => $partner,
        'last_synced_at' => now(),
    ]
);
```

Note: The variable `$directConnect` on line 47 currently reads `b2bDirectConnectInbound` — replace that single variable with the two new variables above.

**Step 2: Run tests**

Run: `php artisan test`
Expected: All existing tests pass (may need minor updates for removed `direct_connect_enabled` field)

**Step 3: Commit**

```bash
git add app/Console/Commands/SyncPartners.php
git commit -m "feat: sync direct connect inbound/outbound and tenant restrictions"
```

---

## Task 5: Update Existing Tests for Direct Connect Split

**Files:**
- Modify: `tests/Feature/PartnerOrganizationTest.php`
- Modify: `database/factories/PartnerOrganizationFactory.php` (if it references direct_connect_enabled)

**Step 1: Check and update factory**

Read `database/factories/PartnerOrganizationFactory.php`. Replace `direct_connect_enabled` with:
```php
'direct_connect_inbound_enabled' => fake()->boolean(20),
'direct_connect_outbound_enabled' => fake()->boolean(20),
```

**Step 2: Update test data**

In `tests/Feature/PartnerOrganizationTest.php`, replace all occurrences of `'direct_connect_enabled' => false` or `true` with:
```php
'direct_connect_inbound_enabled' => false,
'direct_connect_outbound_enabled' => false,
```

This affects the `operators can create partners` test (~line 73-74) and the `operators can update partner policy` test (~line 112-113).

**Step 3: Run tests**

Run: `php artisan test`
Expected: All tests pass

**Step 4: Commit**

```bash
git add tests/Feature/PartnerOrganizationTest.php database/factories/PartnerOrganizationFactory.php
git commit -m "test: update tests for direct connect inbound/outbound split"
```

---

## Task 6: Direct Connect Status Badge on Partner Show Page

**Files:**
- Modify: `resources/js/pages/partners/Show.vue` (add status badge after Access Policies card)

**Step 1: Add Direct Connect status section**

After the Access Policies `</Card>` (line 222), add a new card before Notes:

```vue
<!-- Direct Connect Status -->
<Card>
    <CardHeader>
        <CardTitle class="flex items-center gap-2">
            Direct Connect
            <Badge
                :variant="directConnectStatus.variant"
            >
                {{ directConnectStatus.label }}
            </Badge>
        </CardTitle>
    </CardHeader>
    <CardContent>
        <p class="text-sm text-muted-foreground">
            {{ directConnectStatus.description }}
        </p>
    </CardContent>
</Card>
```

**Step 2: Add computed for status**

In the `<script setup>`, add:

```typescript
const directConnectStatus = computed(() => {
    const inbound = props.partner.direct_connect_inbound_enabled;
    const outbound = props.partner.direct_connect_outbound_enabled;

    if (inbound && outbound) {
        return {
            label: 'Active',
            variant: 'default' as const,
            description: 'Both inbound and outbound direct connect are enabled. The partner must also enable direct connect on their side for Teams shared channels to work.',
        };
    }
    if (inbound || outbound) {
        return {
            label: 'Partial',
            variant: 'secondary' as const,
            description: `Only ${inbound ? 'inbound' : 'outbound'} direct connect is enabled. Enable both directions and ensure the partner has also enabled direct connect for full functionality.`,
        };
    }
    return {
        label: 'Disabled',
        variant: 'outline' as const,
        description: 'Direct connect is disabled. Enable inbound and outbound toggles in Access Policies above to allow Teams shared channels with this partner.',
    };
});
```

**Step 3: Verify in browser**

Run: `composer run dev` (if not running)
Navigate to a partner detail page. Verify the Direct Connect status card appears with the correct badge.

**Step 4: Commit**

```bash
git add resources/js/pages/partners/Show.vue
git commit -m "feat: add direct connect status badge to partner detail page"
```

---

## Task 7: CollaborationSettingsService — Backend

**Files:**
- Create: `app/Services/CollaborationSettingsService.php`
- Test: `tests/Feature/CollaborationSettingsTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/CollaborationSettingsTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant',
        'graph.client_id' => 'test-client',
        'graph.client_secret' => 'test-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
    ]);
    Cache::forget('msgraph_access_token');

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
    ]);
});

test('non-admins cannot access collaboration settings', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);

    $this->actingAs($user)
        ->get(route('admin.collaboration.edit'))
        ->assertForbidden();
});

test('admins can view collaboration settings', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/authorizationPolicy' => Http::response([
            'allowInvitesFrom' => 'adminsAndGuestInviters',
            'allowedToInvite' => [],
            'blockedFromInvite' => [],
        ]),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.collaboration.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Collaboration')
            ->has('settings')
        );
});

test('admins can update collaboration settings', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/authorizationPolicy' => Http::response([], 204),
    ]);

    $this->actingAs($admin)
        ->put(route('admin.collaboration.update'), [
            'allow_invites_from' => 'adminsAndGuestInviters',
            'domain_restriction_mode' => 'none',
            'allowed_domains' => [],
            'blocked_domains' => [],
        ])
        ->assertRedirect();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CollaborationSettingsTest`
Expected: FAIL — route and service don't exist yet

**Step 3: Create CollaborationSettingsService**

Create `app/Services/CollaborationSettingsService.php`:

```php
<?php

namespace App\Services;

class CollaborationSettingsService
{
    public function __construct(private MicrosoftGraphService $graph) {}

    public function getSettings(): array
    {
        return $this->graph->get('/policies/authorizationPolicy');
    }

    public function updateSettings(array $config): array
    {
        return $this->graph->patch('/policies/authorizationPolicy', $config);
    }
}
```

**Step 4: Commit**

```bash
git add app/Services/CollaborationSettingsService.php tests/Feature/CollaborationSettingsTest.php
git commit -m "feat: add CollaborationSettingsService and tests"
```

---

## Task 8: AdminCollaborationController + Routes

**Files:**
- Create: `app/Http/Controllers/Admin/AdminCollaborationController.php`
- Create: `app/Http/Requests/UpdateCollaborationSettingsRequest.php`
- Modify: `routes/admin.php` (add routes)

**Step 1: Create the FormRequest**

Create `app/Http/Requests/UpdateCollaborationSettingsRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCollaborationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'allow_invites_from' => ['required', Rule::in([
                'none',
                'adminsAndGuestInviters',
                'adminsGuestInvitersAndAllMembers',
                'everyone',
            ])],
            'domain_restriction_mode' => ['required', Rule::in(['none', 'allowList', 'blockList'])],
            'allowed_domains' => ['array'],
            'allowed_domains.*' => ['string', 'max:255'],
            'blocked_domains' => ['array'],
            'blocked_domains.*' => ['string', 'max:255'],
        ];
    }
}
```

**Step 2: Create the controller**

Create `app/Http/Controllers/Admin/AdminCollaborationController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCollaborationSettingsRequest;
use App\Services\ActivityLogService;
use App\Services\CollaborationSettingsService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AdminCollaborationController extends Controller
{
    public function __construct(
        private CollaborationSettingsService $collaborationService,
        private ActivityLogService $activityLog,
    ) {}

    public function edit(): Response
    {
        $policy = $this->collaborationService->getSettings();

        return Inertia::render('admin/Collaboration', [
            'settings' => [
                'allow_invites_from' => $policy['allowInvitesFrom'] ?? 'adminsAndGuestInviters',
                'allowed_domains' => collect($policy['allowedToInvite'] ?? [])->pluck('domain')->values()->all(),
                'blocked_domains' => collect($policy['blockedFromInvite'] ?? [])->pluck('domain')->values()->all(),
            ],
        ]);
    }

    public function update(UpdateCollaborationSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $config = [
            'allowInvitesFrom' => $validated['allow_invites_from'],
        ];

        if ($validated['domain_restriction_mode'] === 'allowList') {
            $config['allowedToInvite'] = array_map(
                fn ($d) => ['domain' => $d],
                $validated['allowed_domains'] ?? []
            );
            $config['blockedFromInvite'] = [];
        } elseif ($validated['domain_restriction_mode'] === 'blockList') {
            $config['blockedFromInvite'] = array_map(
                fn ($d) => ['domain' => $d],
                $validated['blocked_domains'] ?? []
            );
            $config['allowedToInvite'] = [];
        } else {
            $config['allowedToInvite'] = [];
            $config['blockedFromInvite'] = [];
        }

        $this->collaborationService->updateSettings($config);

        $this->activityLog->log($request->user(), ActivityAction::SettingsUpdated, null, [
            'group' => 'collaboration',
        ]);

        return redirect()->route('admin.collaboration.edit')->with('success', 'Collaboration settings updated.');
    }
}
```

**Step 3: Add routes**

In `routes/admin.php`, add after the graph routes (~line 13):

```php
use App\Http\Controllers\Admin\AdminCollaborationController;

// Inside the middleware group:
Route::get('collaboration', [AdminCollaborationController::class, 'edit'])->name('admin.collaboration.edit');
Route::put('collaboration', [AdminCollaborationController::class, 'update'])->name('admin.collaboration.update');
```

**Step 4: Run tests**

Run: `php artisan test --filter=CollaborationSettingsTest`
Expected: All 3 tests pass

**Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/AdminCollaborationController.php app/Http/Requests/UpdateCollaborationSettingsRequest.php routes/admin.php
git commit -m "feat: add admin collaboration controller and routes"
```

---

## Task 9: Collaboration Settings Vue Page

**Files:**
- Create: `resources/js/pages/admin/Collaboration.vue`
- Modify: `resources/js/layouts/AdminLayout.vue:3,20` (add nav item)

**Step 1: Add nav item to AdminLayout**

In `resources/js/layouts/AdminLayout.vue`, add import and nav item:

```typescript
import { Cable, Globe, Settings2, Users } from 'lucide-vue-next';

const adminNavItems: NavItem[] = [
    { title: 'Microsoft Graph', href: '/admin/graph', icon: Cable },
    { title: 'Collaboration', href: '/admin/collaboration', icon: Globe },
    { title: 'User Management', href: '/admin/users', icon: Users },
    { title: 'Sync Settings', href: '/admin/sync', icon: Settings2 },
];
```

**Step 2: Create the Collaboration page**

Create `resources/js/pages/admin/Collaboration.vue`:

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputError } from '@/components/ui/input-error';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    settings: {
        allow_invites_from: string;
        allowed_domains: string[];
        blocked_domains: string[];
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Collaboration', href: '/admin/collaboration' },
];

const inviteOptions = [
    { value: 'none', label: 'No one' },
    { value: 'adminsAndGuestInviters', label: 'Admins and Guest Inviters' },
    { value: 'adminsGuestInvitersAndAllMembers', label: 'Admins, Guest Inviters, and Members' },
    { value: 'everyone', label: 'Everyone (including guests)' },
];

// Determine initial domain restriction mode
function getInitialMode(): string {
    if (props.settings.allowed_domains.length > 0) return 'allowList';
    if (props.settings.blocked_domains.length > 0) return 'blockList';
    return 'none';
}

const form = useForm({
    allow_invites_from: props.settings.allow_invites_from,
    domain_restriction_mode: getInitialMode(),
    allowed_domains: props.settings.allowed_domains,
    blocked_domains: props.settings.blocked_domains,
});

const newDomain = ref('');

function addDomain(list: 'allowed_domains' | 'blocked_domains') {
    const domain = newDomain.value.trim().toLowerCase();
    if (domain && !form[list].includes(domain)) {
        form[list].push(domain);
    }
    newDomain.value = '';
}

function removeDomain(list: 'allowed_domains' | 'blocked_domains', index: number) {
    form[list].splice(index, 1);
}

const submit = () => {
    form.put('/admin/collaboration');
};
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <Head title="Collaboration Settings" />
        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="External Collaboration"
                description="Control who can invite guests and which domains are allowed or blocked."
            />

            <form class="space-y-6" @submit.prevent="submit">
                <!-- Guest Invitation Controls -->
                <div class="grid gap-2">
                    <Label for="allow_invites_from">Who can invite guests</Label>
                    <Select v-model="form.allow_invites_from">
                        <SelectTrigger id="allow_invites_from">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in inviteOptions"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.allow_invites_from" />
                </div>

                <!-- Domain Restriction Mode -->
                <div class="grid gap-2">
                    <Label for="domain_mode">Domain restrictions</Label>
                    <Select v-model="form.domain_restriction_mode">
                        <SelectTrigger id="domain_mode">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Allow all domains</SelectItem>
                            <SelectItem value="allowList">Allow only specific domains</SelectItem>
                            <SelectItem value="blockList">Block specific domains</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Domain List (Allow) -->
                <div v-if="form.domain_restriction_mode === 'allowList'" class="grid gap-2">
                    <Label>Allowed domains</Label>
                    <div class="flex gap-2">
                        <Input
                            v-model="newDomain"
                            placeholder="contoso.com"
                            @keydown.enter.prevent="addDomain('allowed_domains')"
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            @click="addDomain('allowed_domains')"
                        >
                            Add
                        </Button>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="(domain, i) in form.allowed_domains"
                            :key="domain"
                            variant="secondary"
                            class="cursor-pointer"
                            @click="removeDomain('allowed_domains', i)"
                        >
                            {{ domain }} &times;
                        </Badge>
                    </div>
                    <InputError :message="form.errors.allowed_domains" />
                </div>

                <!-- Domain List (Block) -->
                <div v-if="form.domain_restriction_mode === 'blockList'" class="grid gap-2">
                    <Label>Blocked domains</Label>
                    <div class="flex gap-2">
                        <Input
                            v-model="newDomain"
                            placeholder="contoso.com"
                            @keydown.enter.prevent="addDomain('blocked_domains')"
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            @click="addDomain('blocked_domains')"
                        >
                            Add
                        </Button>
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <Badge
                            v-for="(domain, i) in form.blocked_domains"
                            :key="domain"
                            variant="secondary"
                            class="cursor-pointer"
                            @click="removeDomain('blocked_domains', i)"
                        >
                            {{ domain }} &times;
                        </Badge>
                    </div>
                    <InputError :message="form.errors.blocked_domains" />
                </div>

                <Button type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Saving…' : 'Save Settings' }}
                </Button>
            </form>
        </div>
    </AdminLayout>
</template>
```

**Step 3: Run type check and lint**

Run: `npm run types:check && npm run lint`
Expected: No errors

**Step 4: Commit**

```bash
git add resources/js/pages/admin/Collaboration.vue resources/js/layouts/AdminLayout.vue
git commit -m "feat: add collaboration settings admin page"
```

---

## Task 10: Tenant Restrictions — Service Methods

**Files:**
- Modify: `app/Services/CrossTenantPolicyService.php` (add two methods)

**Step 1: Add service methods**

Add to `CrossTenantPolicyService.php`:

```php
public function getTenantRestrictions(string $tenantId): ?array
{
    $partner = $this->getPartner($tenantId);

    return $partner['tenantRestrictions'] ?? null;
}

public function updateTenantRestrictions(string $tenantId, array $config): array
{
    return $this->graph->patch("/policies/crossTenantAccessPolicy/partners/{$tenantId}", [
        'tenantRestrictions' => $config,
    ]);
}
```

**Step 2: Commit**

```bash
git add app/Services/CrossTenantPolicyService.php
git commit -m "feat: add tenant restrictions methods to CrossTenantPolicyService"
```

---

## Task 11: Tenant Restrictions — Controller + Validation + Route

**Files:**
- Modify: `app/Http/Requests/UpdatePartnerRequest.php` (add tenant restrictions rules)
- Modify: `app/Http/Controllers/PartnerOrganizationController.php` (handle tenant restrictions in update + buildGraphConfig)

**Step 1: Add validation rules**

In `UpdatePartnerRequest.php`, add:

```php
'tenant_restrictions_enabled' => ['boolean'],
'tenant_restrictions_json' => ['nullable', 'array'],
'tenant_restrictions_json.applications' => ['nullable', 'array'],
'tenant_restrictions_json.applications.accessType' => ['nullable', 'string', 'in:allowed,blocked'],
'tenant_restrictions_json.applications.targets' => ['nullable', 'array'],
'tenant_restrictions_json.applications.targets.*.target' => ['required_with:tenant_restrictions_json.applications.targets', 'string'],
'tenant_restrictions_json.applications.targets.*.targetType' => ['required_with:tenant_restrictions_json.applications.targets', 'string', 'in:application'],
'tenant_restrictions_json.usersAndGroups' => ['nullable', 'array'],
'tenant_restrictions_json.usersAndGroups.accessType' => ['nullable', 'string', 'in:allowed,blocked'],
'tenant_restrictions_json.usersAndGroups.targets' => ['nullable', 'array'],
'tenant_restrictions_json.usersAndGroups.targets.*.target' => ['required_with:tenant_restrictions_json.usersAndGroups.targets', 'string'],
'tenant_restrictions_json.usersAndGroups.targets.*.targetType' => ['required_with:tenant_restrictions_json.usersAndGroups.targets', 'string', 'in:user,group'],
```

**Step 2: Update buildGraphConfig**

Add at the end of `buildGraphConfig()` in `PartnerOrganizationController.php`:

```php
if (isset($data['tenant_restrictions_enabled'])) {
    if ($data['tenant_restrictions_enabled'] && ! empty($data['tenant_restrictions_json'])) {
        $config['tenantRestrictions'] = $data['tenant_restrictions_json'];
    } elseif (! $data['tenant_restrictions_enabled']) {
        $config['tenantRestrictions'] = null;
    }
}
```

**Step 3: Commit**

```bash
git add app/Http/Requests/UpdatePartnerRequest.php app/Http/Controllers/PartnerOrganizationController.php
git commit -m "feat: add tenant restrictions validation and Graph config building"
```

---

## Task 12: Tenant Restrictions — Vue Card on Partner Show Page

**Files:**
- Modify: `resources/js/pages/partners/Show.vue` (add Tenant Restrictions card)

**Step 1: Add tenant restrictions form state**

In the `<script setup>`, add after the notes section:

```typescript
// Tenant Restrictions form state
const restrictionsForm = reactive({
    tenant_restrictions_enabled: props.partner.tenant_restrictions_enabled,
    tenant_restrictions_json: props.partner.tenant_restrictions_json ?? {
        applications: {
            accessType: 'allowed',
            targets: [{ target: 'AllApplications', targetType: 'application' }],
        },
        usersAndGroups: {
            accessType: 'allowed',
            targets: [{ target: 'AllUsers', targetType: 'user' }],
        },
    },
});

const savingRestrictions = ref(false);
const restrictionsSaved = ref(false);

const appMode = ref<'all' | 'allowList' | 'blockList'>(() => {
    const apps = restrictionsForm.tenant_restrictions_json?.applications;
    if (!apps || apps.targets?.[0]?.target === 'AllApplications') return 'all';
    return apps.accessType === 'allowed' ? 'allowList' : 'blockList';
});

const appTargets = ref<{ target: string; name: string }[]>(() => {
    const apps = restrictionsForm.tenant_restrictions_json?.applications;
    if (!apps || apps.targets?.[0]?.target === 'AllApplications') return [];
    return apps.targets?.map((t: any) => ({ target: t.target, name: t.target })) ?? [];
});

const newAppId = ref('');
const newAppName = ref('');

function addApp() {
    const id = newAppId.value.trim();
    const name = newAppName.value.trim() || id;
    if (id && !appTargets.value.find(a => a.target === id)) {
        appTargets.value.push({ target: id, name });
    }
    newAppId.value = '';
    newAppName.value = '';
}

function removeApp(index: number) {
    appTargets.value.splice(index, 1);
}

function saveRestrictions() {
    savingRestrictions.value = true;

    const json: Record<string, any> = {};

    if (appMode.value === 'all') {
        json.applications = {
            accessType: 'allowed',
            targets: [{ target: 'AllApplications', targetType: 'application' }],
        };
    } else {
        json.applications = {
            accessType: appMode.value === 'allowList' ? 'allowed' : 'blocked',
            targets: appTargets.value.map(a => ({ target: a.target, targetType: 'application' })),
        };
    }

    json.usersAndGroups = {
        accessType: 'allowed',
        targets: [{ target: 'AllUsers', targetType: 'user' }],
    };

    router.patch(partners.update.url(props.partner.id), {
        tenant_restrictions_enabled: restrictionsForm.tenant_restrictions_enabled,
        tenant_restrictions_json: restrictionsForm.tenant_restrictions_enabled ? json : null,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            restrictionsSaved.value = true;
            setTimeout(() => { restrictionsSaved.value = false; }, 3000);
        },
        onFinish: () => { savingRestrictions.value = false; },
    });
}
```

**Step 2: Add the card template**

After the Direct Connect Status card and before Notes, add:

```vue
<!-- Tenant Restrictions -->
<Card v-if="canManage">
    <CardHeader>
        <CardTitle class="flex items-center gap-2">
            Tenant Restrictions
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <CircleHelp class="size-3.5 text-muted-foreground" />
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs" side="right">
                        Tenant Restrictions control what your users can access when signing into this partner's tenant. Requires Global Secure Access or a corporate proxy to enforce.
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </CardTitle>
    </CardHeader>
    <CardContent class="flex flex-col gap-4">
        <div class="flex items-center justify-between py-2">
            <div>
                <p class="text-sm font-medium">Enable Tenant Restrictions</p>
                <p class="text-xs text-muted-foreground">
                    Control which apps your users can access in this partner's tenant.
                </p>
            </div>
            <Switch
                :model-value="restrictionsForm.tenant_restrictions_enabled"
                @update:model-value="(val: boolean) => { restrictionsForm.tenant_restrictions_enabled = val; }"
            />
        </div>

        <template v-if="restrictionsForm.tenant_restrictions_enabled">
            <Separator />

            <div class="grid gap-2">
                <Label>Application access</Label>
                <Select v-model="appMode">
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Allow all applications</SelectItem>
                        <SelectItem value="allowList">Allow only specific applications</SelectItem>
                        <SelectItem value="blockList">Block specific applications</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div v-if="appMode !== 'all'" class="grid gap-2">
                <Label>{{ appMode === 'allowList' ? 'Allowed' : 'Blocked' }} applications</Label>
                <div class="flex gap-2">
                    <Input v-model="newAppId" placeholder="Application ID" class="flex-1" />
                    <Input v-model="newAppName" placeholder="Display name (optional)" class="flex-1" />
                    <Button type="button" variant="secondary" @click="addApp">Add</Button>
                </div>
                <div class="flex flex-wrap gap-1">
                    <Badge
                        v-for="(app, i) in appTargets"
                        :key="app.target"
                        variant="secondary"
                        class="cursor-pointer"
                        @click="removeApp(i)"
                    >
                        {{ app.name }} &times;
                    </Badge>
                </div>
            </div>
        </template>

        <div class="flex items-center gap-3 pt-2">
            <Button @click="saveRestrictions" :disabled="savingRestrictions">
                {{ savingRestrictions ? 'Saving…' : 'Save Restrictions' }}
            </Button>
            <span v-if="restrictionsSaved" class="text-sm text-green-600 dark:text-green-400">Saved.</span>
        </div>
    </CardContent>
</Card>
```

**Step 3: Add missing imports to Show.vue**

Add these imports if not already present:
```typescript
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
```

**Step 4: Run type check and lint**

Run: `npm run types:check && npm run lint`
Expected: No errors

**Step 5: Commit**

```bash
git add resources/js/pages/partners/Show.vue
git commit -m "feat: add tenant restrictions card to partner detail page"
```

---

## Task 13: Tenant Restrictions Tests

**Files:**
- Modify: `tests/Feature/PartnerOrganizationTest.php` (add tenant restrictions test)

**Step 1: Add test for tenant restrictions update**

```php
test('operators can update tenant restrictions', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'abc-def-123-456-789012345678']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/*' => Http::response([], 204),
    ]);

    $this->actingAs($user)
        ->patch(route('partners.update', $partner), [
            'tenant_restrictions_enabled' => true,
            'tenant_restrictions_json' => [
                'applications' => [
                    'accessType' => 'blocked',
                    'targets' => [
                        ['target' => 'some-app-id', 'targetType' => 'application'],
                    ],
                ],
                'usersAndGroups' => [
                    'accessType' => 'allowed',
                    'targets' => [
                        ['target' => 'AllUsers', 'targetType' => 'user'],
                    ],
                ],
            ],
        ])
        ->assertRedirect();

    $partner->refresh();
    expect($partner->tenant_restrictions_enabled)->toBeTrue();
    expect($partner->tenant_restrictions_json['applications']['accessType'])->toBe('blocked');
});
```

**Step 2: Run tests**

Run: `php artisan test`
Expected: All tests pass

**Step 3: Commit**

```bash
git add tests/Feature/PartnerOrganizationTest.php
git commit -m "test: add tenant restrictions update test"
```

---

## Task 14: Final Verification

**Step 1: Run full CI check**

Run: `composer run ci:check`
Expected: All checks pass (lint, format, types, tests)

**Step 2: Run the dev seeder if available**

Run: `php artisan migrate:fresh --seed`
Expected: No errors, dev data populated

**Step 3: Manual smoke test**

Run: `composer run dev`

Verify:
- Admin sidebar shows "Collaboration" link
- `/admin/collaboration` loads with invite controls and domain list
- Partner detail page shows Direct Connect status badge
- Partner detail page shows Tenant Restrictions card (for operators/admins)
- Policy toggles show inbound/outbound direct connect separately
- Saving each section works without errors

**Step 4: Final commit if any fixes needed**

```bash
git add -A
git commit -m "fix: address issues found during smoke testing"
```

---

## Summary

| Task | Feature | What |
|------|---------|------|
| 1 | Direct Connect | Migration: split into inbound/outbound |
| 2 | Tenant Restrictions | Migration: add columns |
| 3 | Direct Connect | Update policy config, validation, controller, Vue |
| 4 | Both | Update SyncPartners command |
| 5 | Direct Connect | Update existing tests + factory |
| 6 | Direct Connect | Status badge on partner page |
| 7 | Collaboration | Service + tests |
| 8 | Collaboration | Controller + routes |
| 9 | Collaboration | Vue admin page + nav |
| 10 | Tenant Restrictions | Service methods |
| 11 | Tenant Restrictions | Controller validation + config |
| 12 | Tenant Restrictions | Vue card on partner page |
| 13 | Tenant Restrictions | Tests |
| 14 | All | Final verification |
