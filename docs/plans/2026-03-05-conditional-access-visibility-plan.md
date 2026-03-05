# Conditional Access Policy Visibility — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Surface which Conditional Access policies affect external/guest users per partner, with basic gap detection.

**Architecture:** New `ConditionalAccessPolicy` model with pivot to `PartnerOrganization`. A `ConditionalAccessPolicyService` fetches from Graph API and builds partner mappings. A `sync:conditional-access-policies` command runs on schedule. Read-only controller serves Inertia pages for global list and per-policy detail, plus CA data added to partner show page.

**Tech Stack:** Laravel 12 (PHP 8.2+), Vue 3 + TypeScript, Inertia.js, Microsoft Graph API, Pest PHP

---

### Task 1: Database Migration — `conditional_access_policies` Table

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_conditional_access_policies_table.php`

**Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conditional_access_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_id')->unique();
            $table->string('display_name');
            $table->string('state');
            $table->string('guest_or_external_user_types')->nullable();
            $table->string('external_tenant_scope')->default('all');
            $table->json('external_tenant_ids')->nullable();
            $table->string('target_applications')->default('all');
            $table->json('grant_controls')->nullable();
            $table->json('session_controls')->nullable();
            $table->json('raw_policy_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conditional_access_policies');
    }
};
```

**Step 2: Run migration**

Run: `php artisan migrate`
Expected: Table created successfully.

**Step 3: Commit**

```bash
git add database/migrations/*_create_conditional_access_policies_table.php
git commit -m "feat: add conditional_access_policies migration"
```

---

### Task 2: Database Migration — Pivot Table

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_conditional_access_policy_partner_table.php`

**Step 1: Create the pivot migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conditional_access_policy_partner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conditional_access_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_organization_id')->constrained()->cascadeOnDelete();
            $table->string('matched_user_type');
            $table->timestamps();

            $table->unique(
                ['conditional_access_policy_id', 'partner_organization_id', 'matched_user_type'],
                'ca_policy_partner_type_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conditional_access_policy_partner');
    }
};
```

**Step 2: Run migration**

Run: `php artisan migrate`
Expected: Table created successfully.

**Step 3: Commit**

```bash
git add database/migrations/*_create_conditional_access_policy_partner_table.php
git commit -m "feat: add conditional_access_policy_partner pivot migration"
```

---

### Task 3: ConditionalAccessPolicy Model

**Files:**
- Create: `app/Models/ConditionalAccessPolicy.php`
- Modify: `app/Models/PartnerOrganization.php:42-52` (add relationship)

**Step 1: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ConditionalAccessPolicy extends Model
{
    protected $fillable = [
        'policy_id', 'display_name', 'state',
        'guest_or_external_user_types', 'external_tenant_scope', 'external_tenant_ids',
        'target_applications', 'grant_controls', 'session_controls',
        'raw_policy_json', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'external_tenant_ids' => 'array',
            'grant_controls' => 'array',
            'session_controls' => 'array',
            'raw_policy_json' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(PartnerOrganization::class, 'conditional_access_policy_partner')
            ->withPivot('matched_user_type')
            ->withTimestamps();
    }
}
```

**Step 2: Add relationship to PartnerOrganization**

Add this method to `app/Models/PartnerOrganization.php` after the `guestUsers()` method:

```php
public function conditionalAccessPolicies(): BelongsToMany
{
    return $this->belongsToMany(ConditionalAccessPolicy::class, 'conditional_access_policy_partner')
        ->withPivot('matched_user_type')
        ->withTimestamps();
}
```

Add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` to the imports.

**Step 3: Commit**

```bash
git add app/Models/ConditionalAccessPolicy.php app/Models/PartnerOrganization.php
git commit -m "feat: add ConditionalAccessPolicy model with partner relationship"
```

---

### Task 4: ActivityAction Enum Update

**Files:**
- Modify: `app/Enums/ActivityAction.php:33`

**Step 1: Add new enum case**

Add after the last case in `ActivityAction`:

```php
case ConditionalAccessPoliciesSynced = 'conditional_access_policies_synced';
```

**Step 2: Commit**

```bash
git add app/Enums/ActivityAction.php
git commit -m "feat: add ConditionalAccessPoliciesSynced activity action"
```

---

### Task 5: ConditionalAccessPolicyService — Write Tests First

**Files:**
- Create: `tests/Feature/Services/ConditionalAccessPolicyServiceTest.php`

**Step 1: Write the test file**

```php
<?php

use App\Models\ConditionalAccessPolicy;
use App\Models\PartnerOrganization;
use App\Services\ConditionalAccessPolicyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant-id',
        'graph.client_id' => 'test-client-id',
        'graph.client_secret' => 'test-client-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
    ]);

    Cache::forget('msgraph_access_token');
});

function fakeGraphAuth(): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
    ]);
}

function makeCaPolicy(array $overrides = []): array
{
    return array_merge([
        'id' => 'policy-1',
        'displayName' => 'Require MFA for guests',
        'state' => 'enabled',
        'conditions' => [
            'users' => [
                'includeGuestsOrExternalUsers' => [
                    'guestOrExternalUserTypes' => 'b2bCollaborationGuest',
                    'externalTenants' => [
                        '@odata.type' => '#microsoft.graph.conditionalAccessAllExternalTenants',
                        'membershipKind' => 'all',
                    ],
                ],
            ],
            'applications' => [
                'includeApplications' => ['All'],
            ],
        ],
        'grantControls' => [
            'builtInControls' => ['mfa'],
            'operator' => 'OR',
        ],
        'sessionControls' => null,
    ], $overrides);
}

test('syncPolicies upserts policies from Graph API', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/identity/conditionalAccess/policies' => Http::response([
            'value' => [makeCaPolicy()],
        ]),
    ]);

    $service = app(ConditionalAccessPolicyService::class);
    $service->syncPolicies();

    expect(ConditionalAccessPolicy::count())->toBe(1);
    $policy = ConditionalAccessPolicy::first();
    expect($policy->policy_id)->toBe('policy-1');
    expect($policy->display_name)->toBe('Require MFA for guests');
    expect($policy->state)->toBe('enabled');
    expect($policy->grant_controls)->toBe(['mfa']);
});

test('syncPolicies ignores policies without guest conditions', function () {
    $nonGuestPolicy = [
        'id' => 'policy-internal',
        'displayName' => 'Internal MFA',
        'state' => 'enabled',
        'conditions' => [
            'users' => [
                'includeUsers' => ['All'],
            ],
            'applications' => [
                'includeApplications' => ['All'],
            ],
        ],
        'grantControls' => [
            'builtInControls' => ['mfa'],
            'operator' => 'OR',
        ],
        'sessionControls' => null,
    ];

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/identity/conditionalAccess/policies' => Http::response([
            'value' => [$nonGuestPolicy, makeCaPolicy()],
        ]),
    ]);

    $service = app(ConditionalAccessPolicyService::class);
    $service->syncPolicies();

    expect(ConditionalAccessPolicy::count())->toBe(1);
    expect(ConditionalAccessPolicy::first()->policy_id)->toBe('policy-1');
});

test('syncPolicies maps policies to partners with all tenants scope', function () {
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'tenant-abc']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/identity/conditionalAccess/policies' => Http::response([
            'value' => [makeCaPolicy()],
        ]),
    ]);

    $service = app(ConditionalAccessPolicyService::class);
    $service->syncPolicies();

    expect($partner->conditionalAccessPolicies()->count())->toBe(1);
    expect($partner->conditionalAccessPolicies()->first()->pivot->matched_user_type)->toBe('b2bCollaborationGuest');
});

test('syncPolicies maps policies only to specific tenants when scope is enumerated', function () {
    $matchedPartner = PartnerOrganization::factory()->create(['tenant_id' => 'tenant-match']);
    $unmatchedPartner = PartnerOrganization::factory()->create(['tenant_id' => 'tenant-other']);

    $policy = makeCaPolicy([
        'conditions' => [
            'users' => [
                'includeGuestsOrExternalUsers' => [
                    'guestOrExternalUserTypes' => 'b2bCollaborationGuest',
                    'externalTenants' => [
                        '@odata.type' => '#microsoft.graph.conditionalAccessEnumeratedExternalTenants',
                        'membershipKind' => 'enumerated',
                        'members' => ['tenant-match'],
                    ],
                ],
            ],
            'applications' => [
                'includeApplications' => ['All'],
            ],
        ],
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/identity/conditionalAccess/policies' => Http::response([
            'value' => [$policy],
        ]),
    ]);

    $service = app(ConditionalAccessPolicyService::class);
    $service->syncPolicies();

    expect($matchedPartner->conditionalAccessPolicies()->count())->toBe(1);
    expect($unmatchedPartner->conditionalAccessPolicies()->count())->toBe(0);
});

test('syncPolicies removes stale policies on re-sync', function () {
    ConditionalAccessPolicy::create([
        'policy_id' => 'stale-policy',
        'display_name' => 'Old Policy',
        'state' => 'enabled',
        'synced_at' => now()->subDay(),
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/identity/conditionalAccess/policies' => Http::response([
            'value' => [makeCaPolicy()],
        ]),
    ]);

    $service = app(ConditionalAccessPolicyService::class);
    $service->syncPolicies();

    expect(ConditionalAccessPolicy::count())->toBe(1);
    expect(ConditionalAccessPolicy::first()->policy_id)->toBe('policy-1');
});

test('getUncoveredPartners returns partners with no CA policies', function () {
    $covered = PartnerOrganization::factory()->create();
    $uncovered = PartnerOrganization::factory()->create();

    $policy = ConditionalAccessPolicy::create([
        'policy_id' => 'p1',
        'display_name' => 'Test',
        'state' => 'enabled',
        'synced_at' => now(),
    ]);
    $policy->partners()->attach($covered->id, ['matched_user_type' => 'b2bCollaborationGuest']);

    $service = app(ConditionalAccessPolicyService::class);
    $result = $service->getUncoveredPartners();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($uncovered->id);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ConditionalAccessPolicyServiceTest`
Expected: FAIL — `ConditionalAccessPolicyService` class not found.

**Step 3: Commit**

```bash
git add tests/Feature/Services/ConditionalAccessPolicyServiceTest.php
git commit -m "test: add ConditionalAccessPolicyService tests (red)"
```

---

### Task 6: ConditionalAccessPolicyService — Implementation

**Files:**
- Create: `app/Services/ConditionalAccessPolicyService.php`

**Step 1: Implement the service**

```php
<?php

namespace App\Services;

use App\Models\ConditionalAccessPolicy;
use App\Models\PartnerOrganization;
use Illuminate\Database\Eloquent\Collection;

class ConditionalAccessPolicyService
{
    public function __construct(
        private MicrosoftGraphService $graph,
    ) {}

    public function syncPolicies(): int
    {
        $graphPolicies = $this->fetchPoliciesFromGraph();
        $guestPolicies = $this->filterGuestPolicies($graphPolicies);

        $syncedPolicyIds = [];

        foreach ($guestPolicies as $graphPolicy) {
            $parsed = $this->parsePolicy($graphPolicy);
            $policy = ConditionalAccessPolicy::updateOrCreate(
                ['policy_id' => $graphPolicy['id']],
                array_merge($parsed, ['synced_at' => now()])
            );

            $this->buildPartnerMappings($policy, $graphPolicy);
            $syncedPolicyIds[] = $policy->id;
        }

        // Remove stale policies
        ConditionalAccessPolicy::whereNotIn('id', $syncedPolicyIds)->delete();

        return count($syncedPolicyIds);
    }

    public function getUncoveredPartners(): Collection
    {
        return PartnerOrganization::whereDoesntHave('conditionalAccessPolicies')->get();
    }

    private function fetchPoliciesFromGraph(): array
    {
        $response = $this->graph->get('/identity/conditionalAccess/policies');

        return $response['value'] ?? [];
    }

    private function filterGuestPolicies(array $policies): array
    {
        return array_filter($policies, function (array $policy) {
            return isset($policy['conditions']['users']['includeGuestsOrExternalUsers']);
        });
    }

    private function parsePolicy(array $graphPolicy): array
    {
        $guestConfig = $graphPolicy['conditions']['users']['includeGuestsOrExternalUsers'];
        $externalTenants = $guestConfig['externalTenants'] ?? [];
        $membershipKind = $externalTenants['membershipKind'] ?? 'all';

        $apps = $graphPolicy['conditions']['applications']['includeApplications'] ?? [];
        $targetApps = in_array('All', $apps) ? 'all' : implode(', ', $apps);

        $grantControls = $graphPolicy['grantControls']['builtInControls'] ?? [];
        $sessionControls = [];
        if ($graphPolicy['sessionControls']) {
            foreach ($graphPolicy['sessionControls'] as $key => $value) {
                if ($value !== null && $key !== '@odata.type') {
                    $sessionControls[] = $key;
                }
            }
        }

        return [
            'display_name' => $graphPolicy['displayName'],
            'state' => $graphPolicy['state'],
            'guest_or_external_user_types' => $guestConfig['guestOrExternalUserTypes'] ?? '',
            'external_tenant_scope' => $membershipKind === 'all' ? 'all' : 'specific',
            'external_tenant_ids' => $membershipKind === 'enumerated' ? ($externalTenants['members'] ?? []) : null,
            'target_applications' => $targetApps,
            'grant_controls' => $grantControls,
            'session_controls' => $sessionControls,
            'raw_policy_json' => $graphPolicy,
        ];
    }

    private function buildPartnerMappings(ConditionalAccessPolicy $policy, array $graphPolicy): void
    {
        $guestConfig = $graphPolicy['conditions']['users']['includeGuestsOrExternalUsers'];
        $userTypes = explode(',', $guestConfig['guestOrExternalUserTypes'] ?? '');
        $externalTenants = $guestConfig['externalTenants'] ?? [];
        $membershipKind = $externalTenants['membershipKind'] ?? 'all';

        $partners = match ($membershipKind) {
            'all' => PartnerOrganization::all(),
            'enumerated' => PartnerOrganization::whereIn('tenant_id', $externalTenants['members'] ?? [])->get(),
            default => collect(),
        };

        $pivotData = [];
        foreach ($partners as $partner) {
            foreach ($userTypes as $userType) {
                $userType = trim($userType);
                if ($userType) {
                    $pivotData[$partner->id] = ['matched_user_type' => $userType];
                }
            }
        }

        $policy->partners()->sync($pivotData);
    }
}
```

**Step 2: Run tests**

Run: `php artisan test --filter=ConditionalAccessPolicyServiceTest`
Expected: All 6 tests PASS.

**Step 3: Commit**

```bash
git add app/Services/ConditionalAccessPolicyService.php
git commit -m "feat: add ConditionalAccessPolicyService"
```

---

### Task 7: Sync Command

**Files:**
- Create: `app/Console/Commands/SyncConditionalAccessPolicies.php`
- Modify: `routes/console.php:22`

**Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use App\Services\ConditionalAccessPolicyService;
use Illuminate\Console\Command;

class SyncConditionalAccessPolicies extends Command
{
    protected $signature = 'sync:conditional-access-policies';

    protected $description = 'Sync Conditional Access policies targeting guest/external users from Microsoft Graph API';

    public function handle(ConditionalAccessPolicyService $service): int
    {
        $log = SyncLog::create([
            'type' => 'conditional_access_policies',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Fetching Conditional Access policies from Graph API...');

            $synced = $service->syncPolicies();

            $this->info("Synced {$synced} Conditional Access policies targeting guest/external users.");

            $log->update([
                'status' => 'completed',
                'records_synced' => $synced,
                'completed_at' => now(),
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $this->error("Sync failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
```

**Step 2: Add to scheduler in `routes/console.php`**

Add after line 22 (`Schedule::command('score:partners')->daily();`):

```php
Schedule::command('sync:conditional-access-policies')->cron("*/{$partnersInterval} * * * *");
```

Note: Reuse `$partnersInterval` since CA policies sync at the same frequency as partners.

**Step 3: Commit**

```bash
git add app/Console/Commands/SyncConditionalAccessPolicies.php routes/console.php
git commit -m "feat: add sync:conditional-access-policies command and schedule"
```

---

### Task 8: Controller — Write Tests First

**Files:**
- Create: `tests/Feature/Controllers/ConditionalAccessPolicyControllerTest.php`

**Step 1: Write the test file**

```php
<?php

use App\Models\ConditionalAccessPolicy;
use App\Models\PartnerOrganization;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin', 'is_approved' => true]);
    $this->actingAs($this->user);
});

test('index page renders with policies', function () {
    $policy = ConditionalAccessPolicy::create([
        'policy_id' => 'p-1',
        'display_name' => 'Require MFA for guests',
        'state' => 'enabled',
        'grant_controls' => ['mfa'],
        'synced_at' => now(),
    ]);

    $response = $this->get('/conditional-access');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('conditional-access/Index')
        ->has('policies.data', 1)
        ->where('policies.data.0.display_name', 'Require MFA for guests')
        ->has('uncoveredPartnerCount')
    );
});

test('index shows uncovered partner count', function () {
    PartnerOrganization::factory()->count(3)->create();

    $policy = ConditionalAccessPolicy::create([
        'policy_id' => 'p-1',
        'display_name' => 'Test',
        'state' => 'enabled',
        'synced_at' => now(),
    ]);
    $partner = PartnerOrganization::first();
    $policy->partners()->attach($partner->id, ['matched_user_type' => 'b2bCollaborationGuest']);

    $response = $this->get('/conditional-access');

    $response->assertInertia(fn ($page) => $page
        ->where('uncoveredPartnerCount', 2)
    );
});

test('show page renders with policy and partners', function () {
    $policy = ConditionalAccessPolicy::create([
        'policy_id' => 'p-1',
        'display_name' => 'Require MFA for guests',
        'state' => 'enabled',
        'grant_controls' => ['mfa'],
        'synced_at' => now(),
    ]);
    $partner = PartnerOrganization::factory()->create();
    $policy->partners()->attach($partner->id, ['matched_user_type' => 'b2bCollaborationGuest']);

    $response = $this->get("/conditional-access/{$policy->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('conditional-access/Show')
        ->where('policy.display_name', 'Require MFA for guests')
        ->has('policy.partners', 1)
    );
});

test('partner show page includes conditional access policies', function () {
    $partner = PartnerOrganization::factory()->create();
    $policy = ConditionalAccessPolicy::create([
        'policy_id' => 'p-1',
        'display_name' => 'MFA Policy',
        'state' => 'enabled',
        'synced_at' => now(),
    ]);
    $policy->partners()->attach($partner->id, ['matched_user_type' => 'b2bCollaborationGuest']);

    $response = $this->get("/partners/{$partner->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('conditionalAccessPolicies', 1)
    );
});

test('viewer role can access conditional access index', function () {
    $viewer = User::factory()->create(['role' => 'viewer', 'is_approved' => true]);
    $this->actingAs($viewer);

    $response = $this->get('/conditional-access');

    $response->assertOk();
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ConditionalAccessPolicyControllerTest`
Expected: FAIL — route not defined / controller not found.

**Step 3: Commit**

```bash
git add tests/Feature/Controllers/ConditionalAccessPolicyControllerTest.php
git commit -m "test: add ConditionalAccessPolicyController tests (red)"
```

---

### Task 9: Controller Implementation

**Files:**
- Create: `app/Http/Controllers/ConditionalAccessPolicyController.php`
- Modify: `routes/web.php:36` (add routes)
- Modify: `app/Http/Controllers/PartnerOrganizationController.php:102-118` (add CA policies to show props)

**Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\ConditionalAccessPolicy;
use App\Models\PartnerOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConditionalAccessPolicyController extends Controller
{
    public function index(): Response
    {
        $policies = ConditionalAccessPolicy::withCount('partners')
            ->orderBy('display_name')
            ->paginate(25);

        $uncoveredPartnerCount = PartnerOrganization::whereDoesntHave('conditionalAccessPolicies')->count();

        return Inertia::render('conditional-access/Index', [
            'policies' => $policies,
            'uncoveredPartnerCount' => $uncoveredPartnerCount,
        ]);
    }

    public function show(ConditionalAccessPolicy $conditionalAccessPolicy): Response
    {
        $conditionalAccessPolicy->load('partners');

        return Inertia::render('conditional-access/Show', [
            'policy' => $conditionalAccessPolicy,
        ]);
    }
}
```

**Step 2: Add routes to `routes/web.php`**

Add after the access-reviews routes block (around line 36), inside the auth middleware group:

```php
Route::get('conditional-access', [ConditionalAccessPolicyController::class, 'index'])->name('conditional-access.index');
Route::get('conditional-access/{conditionalAccessPolicy}', [ConditionalAccessPolicyController::class, 'show'])->name('conditional-access.show');
```

Add the import at the top of the file:

```php
use App\Http\Controllers\ConditionalAccessPolicyController;
```

**Step 3: Add CA policies to partner show page**

In `app/Http/Controllers/PartnerOrganizationController.php`, modify the `show()` method. After `$partner->load('owner');` (line 103), add:

```php
$conditionalAccessPolicies = $partner->conditionalAccessPolicies()
    ->withPivot('matched_user_type')
    ->get();
```

Add `'conditionalAccessPolicies' => $conditionalAccessPolicies,` to the Inertia::render props array (after the `'canManage'` line).

**Step 4: Run tests**

Run: `php artisan test --filter=ConditionalAccessPolicyControllerTest`
Expected: All 5 tests PASS.

**Step 5: Commit**

```bash
git add app/Http/Controllers/ConditionalAccessPolicyController.php app/Http/Controllers/PartnerOrganizationController.php routes/web.php
git commit -m "feat: add ConditionalAccessPolicyController with routes"
```

---

### Task 10: TypeScript Types

**Files:**
- Create: `resources/js/types/conditional-access.ts`

**Step 1: Create the types file**

```typescript
import type { PartnerOrganization } from './partner';

export type ConditionalAccessPolicy = {
    id: number;
    policy_id: string;
    display_name: string;
    state: string;
    guest_or_external_user_types: string | null;
    external_tenant_scope: string;
    external_tenant_ids: string[] | null;
    target_applications: string;
    grant_controls: string[] | null;
    session_controls: string[] | null;
    synced_at: string | null;
    partners_count?: number;
    partners?: (PartnerOrganization & {
        pivot: { matched_user_type: string };
    })[];
};
```

**Step 2: Commit**

```bash
git add resources/js/types/conditional-access.ts
git commit -m "feat: add ConditionalAccessPolicy TypeScript type"
```

---

### Task 11: Frontend — Index Page

**Files:**
- Create: `resources/js/pages/conditional-access/Index.vue`

**Step 1: Create the Index page**

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import type { ConditionalAccessPolicy } from '@/types/conditional-access';
import type { Paginated } from '@/types/partner';

defineProps<{
    policies: Paginated<ConditionalAccessPolicy>;
    uncoveredPartnerCount: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Conditional Access', href: '/conditional-access' },
];

function stateVariant(
    state: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<
        string,
        'default' | 'destructive' | 'outline' | 'secondary'
    > = {
        enabled: 'default',
        disabled: 'outline',
        enabledForReportingButNotEnforced: 'secondary',
    };
    return map[state] ?? 'outline';
}

function stateLabel(state: string): string {
    const map: Record<string, string> = {
        enabled: 'Enabled',
        disabled: 'Disabled',
        enabledForReportingButNotEnforced: 'Report-only',
    };
    return map[state] ?? state;
}

function formatGrantControls(controls: string[] | null): string {
    if (!controls || controls.length === 0) return '\u2014';
    const labels: Record<string, string> = {
        mfa: 'MFA',
        compliantDevice: 'Compliant device',
        domainJoinedDevice: 'Domain joined',
        approvedApplication: 'Approved app',
        compliantApplication: 'Compliant app',
        passwordChange: 'Password change',
        block: 'Block',
    };
    return controls.map((c) => labels[c] ?? c).join(', ');
}
</script>

<template>
    <Head title="Conditional Access" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div>
                <h1 class="text-2xl font-semibold">Conditional Access</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Conditional Access policies targeting guest and external
                    users.
                </p>
            </div>

            <div
                v-if="uncoveredPartnerCount > 0"
                class="flex items-center gap-3 rounded-lg border border-yellow-300 bg-yellow-50 p-4 dark:border-yellow-700 dark:bg-yellow-950"
            >
                <AlertTriangle
                    class="size-5 shrink-0 text-yellow-600 dark:text-yellow-400"
                />
                <p class="text-sm text-yellow-800 dark:text-yellow-200">
                    <strong>{{ uncoveredPartnerCount }}</strong>
                    partner{{
                        uncoveredPartnerCount === 1 ? '' : 's'
                    }}
                    ha{{ uncoveredPartnerCount === 1 ? 's' : 've' }} no
                    Conditional Access policies targeting their guests.
                </p>
            </div>

            <Card v-if="policies.data.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No Conditional Access policies targeting guest users found.
                    Run the sync command or wait for the next scheduled sync.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Policy Name</TableHead>
                        <TableHead>State</TableHead>
                        <TableHead>Grant Controls</TableHead>
                        <TableHead>Target Apps</TableHead>
                        <TableHead>Affected Partners</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="policy in policies.data"
                        :key="policy.id"
                    >
                        <TableCell>
                            <Link
                                :href="`/conditional-access/${policy.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ policy.display_name }}
                            </Link>
                        </TableCell>
                        <TableCell>
                            <Badge :variant="stateVariant(policy.state)">
                                {{ stateLabel(policy.state) }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{
                            formatGrantControls(policy.grant_controls)
                        }}</TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    policy.target_applications === 'all'
                                        ? 'secondary'
                                        : 'outline'
                                "
                            >
                                {{
                                    policy.target_applications === 'all'
                                        ? 'All apps'
                                        : policy.target_applications
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ policy.partners_count ?? 0 }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/conditional-access/Index.vue
git commit -m "feat: add Conditional Access index page"
```

---

### Task 12: Frontend — Show Page

**Files:**
- Create: `resources/js/pages/conditional-access/Show.vue`

**Step 1: Create the Show page**

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import type { ConditionalAccessPolicy } from '@/types/conditional-access';

const props = defineProps<{
    policy: ConditionalAccessPolicy;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Conditional Access', href: '/conditional-access' },
    {
        title: props.policy.display_name,
        href: `/conditional-access/${props.policy.id}`,
    },
];

function stateVariant(
    state: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<
        string,
        'default' | 'destructive' | 'outline' | 'secondary'
    > = {
        enabled: 'default',
        disabled: 'outline',
        enabledForReportingButNotEnforced: 'secondary',
    };
    return map[state] ?? 'outline';
}

function stateLabel(state: string): string {
    const map: Record<string, string> = {
        enabled: 'Enabled',
        disabled: 'Disabled',
        enabledForReportingButNotEnforced: 'Report-only',
    };
    return map[state] ?? state;
}

function formatUserTypes(types: string | null): string {
    if (!types) return '\u2014';
    const labels: Record<string, string> = {
        b2bCollaborationGuest: 'B2B Collaboration Guest',
        b2bCollaborationMember: 'B2B Collaboration Member',
        b2bDirectConnectUser: 'B2B Direct Connect User',
        internalGuest: 'Internal Guest',
        serviceProvider: 'Service Provider',
        otherExternalUser: 'Other External User',
    };
    return types
        .split(',')
        .map((t) => labels[t.trim()] ?? t.trim())
        .join(', ');
}

function formatControls(controls: string[] | null): string {
    if (!controls || controls.length === 0) return 'None';
    const labels: Record<string, string> = {
        mfa: 'Require MFA',
        compliantDevice: 'Require compliant device',
        domainJoinedDevice: 'Require domain joined device',
        approvedApplication: 'Require approved app',
        compliantApplication: 'Require compliant app',
        passwordChange: 'Require password change',
        block: 'Block access',
    };
    return controls.map((c) => labels[c] ?? c).join(', ');
}

const entraUrl = `https://entra.microsoft.com/#view/Microsoft_AAD_ConditionalAccess/PolicyBlade/policyId/${props.policy.policy_id}`;
</script>

<template>
    <Head :title="policy.display_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold">
                            {{ policy.display_name }}
                        </h1>
                        <Badge :variant="stateVariant(policy.state)">
                            {{ stateLabel(policy.state) }}
                        </Badge>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Policy ID: {{ policy.policy_id }}
                    </p>
                </div>
                <a :href="entraUrl" target="_blank" rel="noopener noreferrer">
                    <Button variant="outline" size="sm">
                        <ExternalLink class="mr-2 size-4" />
                        View in Entra
                    </Button>
                </a>
            </div>

            <Separator />

            <Card>
                <CardHeader>
                    <CardTitle>Policy Details</CardTitle>
                </CardHeader>
                <CardContent
                    class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm"
                >
                    <span class="text-muted-foreground">Targeted User Types</span>
                    <span>{{
                        formatUserTypes(
                            policy.guest_or_external_user_types,
                        )
                    }}</span>

                    <span class="text-muted-foreground">External Tenant Scope</span>
                    <span>{{
                        policy.external_tenant_scope === 'all'
                            ? 'All external tenants'
                            : `Specific tenants (${policy.external_tenant_ids?.length ?? 0})`
                    }}</span>

                    <span class="text-muted-foreground">Target Applications</span>
                    <span>{{
                        policy.target_applications === 'all'
                            ? 'All applications'
                            : policy.target_applications
                    }}</span>

                    <span class="text-muted-foreground">Grant Controls</span>
                    <span>{{
                        formatControls(policy.grant_controls)
                    }}</span>

                    <span class="text-muted-foreground">Session Controls</span>
                    <span>{{
                        formatControls(policy.session_controls)
                    }}</span>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle
                        >Affected Partners ({{
                            policy.partners?.length ?? 0
                        }})</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <Table v-if="policy.partners?.length">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Partner</TableHead>
                                <TableHead>Domain</TableHead>
                                <TableHead>Matched User Type</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="partner in policy.partners"
                                :key="partner.id"
                            >
                                <TableCell>
                                    <Link
                                        :href="`/partners/${partner.id}`"
                                        class="font-medium hover:underline"
                                    >
                                        {{ partner.display_name }}
                                    </Link>
                                </TableCell>
                                <TableCell>{{
                                    partner.domain ?? '\u2014'
                                }}</TableCell>
                                <TableCell>
                                    <Badge variant="secondary">
                                        {{ partner.pivot.matched_user_type }}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p
                        v-else
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No partners matched by this policy.
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/conditional-access/Show.vue
git commit -m "feat: add Conditional Access show page"
```

---

### Task 13: Frontend — Partner Show CA Section

**Files:**
- Modify: `resources/js/pages/partners/Show.vue`

**Step 1: Add CA section to partner show page**

In the `<script setup>` section, add these imports:

```typescript
import { AlertTriangle, Shield } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { ConditionalAccessPolicy } from '@/types/conditional-access';
```

Note: `Link` is already imported from `@inertiajs/vue3`. Just add `AlertTriangle`, `Shield`, the Table components, and the type import.

Update the props to include:

```typescript
const props = defineProps<{
    partner: PartnerOrganization;
    guests: Paginated<GuestUser>;
    canManage: boolean;
    conditionalAccessPolicies: (ConditionalAccessPolicy & {
        pivot: { matched_user_type: string };
    })[];
}>();
```

Add this template block **before** the Guest Users card (before `<!-- Guest Users -->`):

```html
<!-- Conditional Access Policies -->
<Card>
    <CardHeader>
        <CardTitle class="flex items-center gap-2">
            <Shield class="size-5" />
            Conditional Access ({{ conditionalAccessPolicies.length }})
        </CardTitle>
    </CardHeader>
    <CardContent>
        <div
            v-if="conditionalAccessPolicies.length === 0"
            class="flex items-center gap-3 rounded-lg border border-yellow-300 bg-yellow-50 p-4 dark:border-yellow-700 dark:bg-yellow-950"
        >
            <AlertTriangle
                class="size-5 shrink-0 text-yellow-600 dark:text-yellow-400"
            />
            <p class="text-sm text-yellow-800 dark:text-yellow-200">
                No Conditional Access policies target this partner's
                guests.
                <Link
                    href="/conditional-access"
                    class="underline"
                    >View all policies</Link
                >
            </p>
        </div>
        <Table v-else>
            <TableHeader>
                <TableRow>
                    <TableHead>Policy</TableHead>
                    <TableHead>State</TableHead>
                    <TableHead>Matched Type</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="policy in conditionalAccessPolicies"
                    :key="policy.id"
                >
                    <TableCell>
                        <Link
                            :href="`/conditional-access/${policy.id}`"
                            class="font-medium hover:underline"
                        >
                            {{ policy.display_name }}
                        </Link>
                    </TableCell>
                    <TableCell>
                        <Badge
                            :variant="
                                policy.state === 'enabled'
                                    ? 'default'
                                    : policy.state ===
                                        'enabledForReportingButNotEnforced'
                                      ? 'secondary'
                                      : 'outline'
                            "
                        >
                            {{
                                policy.state === 'enabled'
                                    ? 'Enabled'
                                    : policy.state ===
                                        'enabledForReportingButNotEnforced'
                                      ? 'Report-only'
                                      : 'Disabled'
                            }}
                        </Badge>
                    </TableCell>
                    <TableCell>
                        <Badge variant="secondary">
                            {{ policy.pivot.matched_user_type }}
                        </Badge>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </CardContent>
</Card>
```

**Step 2: Commit**

```bash
git add resources/js/pages/partners/Show.vue
git commit -m "feat: add Conditional Access section to partner detail page"
```

---

### Task 14: Sidebar Navigation

**Files:**
- Modify: `resources/js/components/AppSidebar.vue:3,37-42`

**Step 1: Add Shield import and nav item**

Add `Shield` to the lucide-vue-next import (line 3):

```typescript
import {
    Activity,
    Building2,
    ClipboardCheck,
    FileStack,
    LayoutGrid,
    Settings,
    Shield,
    Users,
} from 'lucide-vue-next';
```

Add the Conditional Access nav item after Access Reviews (after line 41):

```typescript
{
    title: 'Conditional Access',
    href: '/conditional-access',
    icon: Shield,
},
```

**Step 2: Commit**

```bash
git add resources/js/components/AppSidebar.vue
git commit -m "feat: add Conditional Access to sidebar navigation"
```

---

### Task 15: Generate Route Helpers and Type Check

**Step 1: Generate Wayfinder route helpers**

Run: `php artisan wayfinder:generate`
Expected: Route helpers generated.

**Step 2: Run TypeScript type check**

Run: `npm run types:check`
Expected: No type errors.

**Step 3: Run linting**

Run: `composer run lint && npm run lint && npm run format`
Expected: No linting errors (or auto-fixed).

**Step 4: Commit any generated/fixed files**

```bash
git add -A
git commit -m "chore: generate route helpers and fix lint"
```

---

### Task 16: Run Full Test Suite

**Step 1: Run all tests**

Run: `composer run test`
Expected: All tests pass (existing + new).

**Step 2: If any tests fail, fix and re-run**

Fix issues, then run: `composer run test`

**Step 3: Commit any fixes**

```bash
git add -A
git commit -m "fix: resolve test failures"
```

---

### Task 17: Run Full CI Check

**Step 1: Run CI check**

Run: `composer run ci:check`
Expected: All checks pass (lint + format + types + tests).

**Step 2: Final commit if needed**

```bash
git add -A
git commit -m "chore: ci fixes"
```
