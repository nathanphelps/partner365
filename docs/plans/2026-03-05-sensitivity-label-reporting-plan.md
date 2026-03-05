# Sensitivity Label Reporting & Partner Impact — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add read-only visibility into Microsoft Information Protection sensitivity labels and their impact on partner organizations, mirroring the existing conditional access policy feature.

**Architecture:** Fetch sensitivity labels, label policies, and site-level label assignments from the Microsoft Graph API. Store locally and map to partner organizations via label policy targeting and site sharing. Display in dedicated pages, partner detail, and compliance reports.

**Tech Stack:** Laravel 12 / PHP 8.2, Vue 3 + TypeScript + Inertia.js, Pest PHP tests, Microsoft Graph API, shadcn-vue UI components.

---

### Task 1: Database Migration — Core Tables

**Files:**
- Create: `database/migrations/2026_03_05_100000_create_sensitivity_labels_tables.php`

**Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensitivity_labels', function (Blueprint $table) {
            $table->id();
            $table->string('label_id')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color')->nullable();
            $table->string('tooltip')->nullable();
            $table->json('scope')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('parent_label_id')->nullable()->constrained('sensitivity_labels')->nullOnDelete();
            $table->string('protection_type')->default('none');
            $table->json('raw_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sensitivity_label_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_id')->unique();
            $table->string('name');
            $table->string('target_type')->default('all_users');
            $table->json('target_groups')->nullable();
            $table->json('labels')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sensitivity_label_partner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sensitivity_label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_organization_id')->constrained()->cascadeOnDelete();
            $table->string('matched_via');
            $table->string('policy_name')->nullable();
            $table->string('site_name')->nullable();
            $table->timestamps();

            $table->unique(
                ['sensitivity_label_id', 'partner_organization_id', 'matched_via', 'policy_name', 'site_name'],
                'sl_partner_match_unique'
            );
        });

        Schema::create('site_sensitivity_labels', function (Blueprint $table) {
            $table->id();
            $table->string('site_id')->unique();
            $table->string('site_name');
            $table->string('site_url');
            $table->foreignId('sensitivity_label_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('external_sharing_enabled')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_sensitivity_labels');
        Schema::dropIfExists('sensitivity_label_partner');
        Schema::dropIfExists('sensitivity_label_policies');
        Schema::dropIfExists('sensitivity_labels');
    }
};
```

**Step 2: Run migration**

Run: `php artisan migrate`
Expected: All 4 tables created successfully.

**Step 3: Commit**

```bash
git add database/migrations/2026_03_05_100000_create_sensitivity_labels_tables.php
git commit -m "feat: add sensitivity labels database migration"
```

---

### Task 2: Eloquent Models

**Files:**
- Create: `app/Models/SensitivityLabel.php`
- Create: `app/Models/SensitivityLabelPolicy.php`
- Create: `app/Models/SiteSensitivityLabel.php`
- Modify: `app/Models/PartnerOrganization.php:54-59` (add relationship)

**Step 1: Create SensitivityLabel model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SensitivityLabel extends Model
{
    protected $fillable = [
        'label_id', 'name', 'description', 'color', 'tooltip',
        'scope', 'priority', 'is_active', 'parent_label_id',
        'protection_type', 'raw_json', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'is_active' => 'boolean',
            'raw_json' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SensitivityLabel::class, 'parent_label_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(SensitivityLabel::class, 'parent_label_id');
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(PartnerOrganization::class, 'sensitivity_label_partner')
            ->withPivot('matched_via', 'policy_name', 'site_name')
            ->withTimestamps();
    }
}
```

**Step 2: Create SensitivityLabelPolicy model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SensitivityLabelPolicy extends Model
{
    protected $fillable = [
        'policy_id', 'name', 'target_type', 'target_groups',
        'labels', 'raw_json', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'target_groups' => 'array',
            'labels' => 'array',
            'raw_json' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
```

**Step 3: Create SiteSensitivityLabel model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteSensitivityLabel extends Model
{
    protected $fillable = [
        'site_id', 'site_name', 'site_url',
        'sensitivity_label_id', 'external_sharing_enabled',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'external_sharing_enabled' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function sensitivityLabel(): BelongsTo
    {
        return $this->belongsTo(SensitivityLabel::class);
    }
}
```

**Step 4: Add relationship to PartnerOrganization**

In `app/Models/PartnerOrganization.php`, add after the `conditionalAccessPolicies()` method (after line 59):

```php
public function sensitivityLabels(): BelongsToMany
{
    return $this->belongsToMany(SensitivityLabel::class, 'sensitivity_label_partner')
        ->withPivot('matched_via', 'policy_name', 'site_name')
        ->withTimestamps();
}
```

**Step 5: Commit**

```bash
git add app/Models/SensitivityLabel.php app/Models/SensitivityLabelPolicy.php app/Models/SiteSensitivityLabel.php app/Models/PartnerOrganization.php
git commit -m "feat: add sensitivity label Eloquent models and partner relationship"
```

---

### Task 3: Add ActivityAction Enum Value

**Files:**
- Modify: `app/Enums/ActivityAction.php:34` (add new case)

**Step 1: Add enum case**

In `app/Enums/ActivityAction.php`, add after `ConditionalAccessPoliciesSynced` (line 34):

```php
case SensitivityLabelsSynced = 'sensitivity_labels_synced';
```

**Step 2: Commit**

```bash
git add app/Enums/ActivityAction.php
git commit -m "feat: add SensitivityLabelsSynced activity action"
```

---

### Task 4: SensitivityLabelService — Tests First

**Files:**
- Create: `tests/Feature/Services/SensitivityLabelServiceTest.php`

**Step 1: Write the test file**

```php
<?php

use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\SensitivityLabelPolicy;
use App\Models\SiteSensitivityLabel;
use App\Services\SensitivityLabelService;
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

function makeGraphLabel(array $overrides = []): array
{
    return array_merge([
        'id' => 'label-1',
        'name' => 'Confidential',
        'description' => 'Confidential data',
        'color' => 'red',
        'tooltip' => 'Apply to confidential content',
        'isActive' => true,
        'priority' => 2,
        'parent' => null,
        'contentFormats' => ['file', 'email', 'site', 'group'],
        'protectionSettings' => [
            'encryptionEnabled' => true,
        ],
    ], $overrides);
}

function makeGraphLabelPolicy(array $overrides = []): array
{
    return array_merge([
        'id' => 'policy-1',
        'name' => 'Default Policy',
        'settings' => [
            'labels' => [
                ['labelId' => 'label-1', 'isDefault' => false],
            ],
        ],
        'scopes' => [
            'users' => [
                'included' => [
                    'allUsersAndGuests' => true,
                    'groups' => [],
                ],
            ],
        ],
    ], $overrides);
}

function fakeGraphForSensitivityLabels(array $labels = [], array $policies = [], array $sites = []): void
{
    $fakes = [
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/security/informationProtection/sensitivityLabels' => Http::response([
            'value' => $labels,
        ]),
    ];

    // Site sensitivity label responses handled per-site by sequence if needed
    if (! empty($sites)) {
        $fakes['graph.microsoft.com/v1.0/sites*'] = Http::response([
            'value' => $sites,
        ]);
    }

    Http::fake($fakes);
}

test('syncLabels upserts labels from Graph API', function () {
    fakeGraphForSensitivityLabels([makeGraphLabel()]);

    $service = app(SensitivityLabelService::class);
    $result = $service->syncLabels();

    expect(SensitivityLabel::count())->toBe(1);
    $label = SensitivityLabel::first();
    expect($label->label_id)->toBe('label-1');
    expect($label->name)->toBe('Confidential');
    expect($label->is_active)->toBeTrue();
    expect($label->protection_type)->toBe('encryption');
    expect($result['labels_synced'])->toBe(1);
});

test('syncLabels handles sub-labels via parent reference', function () {
    $parent = makeGraphLabel(['id' => 'parent-1', 'name' => 'Confidential']);
    $child = makeGraphLabel([
        'id' => 'child-1',
        'name' => 'Confidential - Internal Only',
        'parent' => ['id' => 'parent-1'],
    ]);

    fakeGraphForSensitivityLabels([$parent, $child]);

    $service = app(SensitivityLabelService::class);
    $service->syncLabels();

    expect(SensitivityLabel::count())->toBe(2);
    $childModel = SensitivityLabel::where('label_id', 'child-1')->first();
    expect($childModel->parent_label_id)->toBe(SensitivityLabel::where('label_id', 'parent-1')->first()->id);
});

test('syncLabels removes stale labels on re-sync', function () {
    SensitivityLabel::create([
        'label_id' => 'stale-label',
        'name' => 'Old Label',
        'protection_type' => 'none',
        'synced_at' => now()->subDay(),
    ]);

    fakeGraphForSensitivityLabels([makeGraphLabel()]);

    $service = app(SensitivityLabelService::class);
    $service->syncLabels();

    expect(SensitivityLabel::count())->toBe(1);
    expect(SensitivityLabel::first()->label_id)->toBe('label-1');
});

test('syncLabels parses scope from contentFormats', function () {
    $label = makeGraphLabel([
        'contentFormats' => ['file', 'email'],
    ]);

    fakeGraphForSensitivityLabels([$label]);

    $service = app(SensitivityLabelService::class);
    $service->syncLabels();

    $model = SensitivityLabel::first();
    expect($model->scope)->toBe(['files_emails']);
});

test('syncLabels detects protection type', function () {
    $noProtection = makeGraphLabel([
        'id' => 'label-none',
        'protectionSettings' => null,
    ]);
    $encrypted = makeGraphLabel([
        'id' => 'label-enc',
        'protectionSettings' => ['encryptionEnabled' => true],
    ]);

    fakeGraphForSensitivityLabels([$noProtection, $encrypted]);

    $service = app(SensitivityLabelService::class);
    $service->syncLabels();

    expect(SensitivityLabel::where('label_id', 'label-none')->first()->protection_type)->toBe('none');
    expect(SensitivityLabel::where('label_id', 'label-enc')->first()->protection_type)->toBe('encryption');
});

test('buildPartnerMappings maps all partners when policy targets all users and guests', function () {
    $label = SensitivityLabel::create([
        'label_id' => 'label-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);

    $policy = SensitivityLabelPolicy::create([
        'policy_id' => 'policy-1',
        'name' => 'Default Policy',
        'target_type' => 'all_users_and_guests',
        'labels' => ['label-1'],
        'synced_at' => now(),
    ]);

    PartnerOrganization::factory()->count(2)->create();

    $service = app(SensitivityLabelService::class);
    $service->buildPartnerMappings();

    expect($label->partners()->count())->toBe(2);
    expect($label->partners()->first()->pivot->matched_via)->toBe('label_policy');
    expect($label->partners()->first()->pivot->policy_name)->toBe('Default Policy');
});

test('buildPartnerMappings maps via site assignment when site has external sharing', function () {
    $label = SensitivityLabel::create([
        'label_id' => 'label-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);

    SiteSensitivityLabel::create([
        'site_id' => 'site-1',
        'site_name' => 'Project Alpha',
        'site_url' => 'https://contoso.sharepoint.com/sites/alpha',
        'sensitivity_label_id' => $label->id,
        'external_sharing_enabled' => true,
        'synced_at' => now(),
    ]);

    $partner = PartnerOrganization::factory()->create();
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
    ]);

    $service = app(SensitivityLabelService::class);
    $service->buildPartnerMappings();

    expect($label->partners()->count())->toBeGreaterThanOrEqual(1);
});

test('getUncoveredPartners returns partners with no sensitivity labels', function () {
    $covered = PartnerOrganization::factory()->create();
    $uncovered = PartnerOrganization::factory()->create();

    $label = SensitivityLabel::create([
        'label_id' => 'l1',
        'name' => 'Test',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);
    $label->partners()->attach($covered->id, [
        'matched_via' => 'label_policy',
        'policy_name' => 'Default',
    ]);

    $service = app(SensitivityLabelService::class);
    $result = $service->getUncoveredPartners();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($uncovered->id);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SensitivityLabelServiceTest`
Expected: FAIL — `SensitivityLabelService` class not found.

**Step 3: Commit**

```bash
git add tests/Feature/Services/SensitivityLabelServiceTest.php
git commit -m "test: add sensitivity label service tests (red)"
```

---

### Task 5: SensitivityLabelService — Implementation

**Files:**
- Create: `app/Services/SensitivityLabelService.php`

**Step 1: Implement the service**

```php
<?php

namespace App\Services;

use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\SensitivityLabelPolicy;
use App\Models\SiteSensitivityLabel;
use Illuminate\Database\Eloquent\Collection;

class SensitivityLabelService
{
    public function __construct(
        private MicrosoftGraphService $graph,
    ) {}

    public function syncLabels(): array
    {
        $graphLabels = $this->fetchLabelsFromGraph();

        $syncedLabelIds = [];

        // First pass: create/update all labels (without parent references)
        foreach ($graphLabels as $graphLabel) {
            $parsed = $this->parseLabel($graphLabel);
            $label = SensitivityLabel::updateOrCreate(
                ['label_id' => $graphLabel['id']],
                array_merge($parsed, ['synced_at' => now()])
            );
            $syncedLabelIds[] = $label->id;
        }

        // Second pass: link parent-child relationships
        foreach ($graphLabels as $graphLabel) {
            if (! empty($graphLabel['parent']['id'])) {
                $parent = SensitivityLabel::where('label_id', $graphLabel['parent']['id'])->first();
                if ($parent) {
                    SensitivityLabel::where('label_id', $graphLabel['id'])
                        ->update(['parent_label_id' => $parent->id]);
                }
            }
        }

        SensitivityLabel::whereNotIn('id', $syncedLabelIds)->delete();

        return ['labels_synced' => count($syncedLabelIds)];
    }

    public function syncPolicies(): int
    {
        $graphPolicies = $this->fetchLabelPoliciesFromGraph();

        $syncedIds = [];

        foreach ($graphPolicies as $graphPolicy) {
            $parsed = $this->parsePolicy($graphPolicy);
            $policy = SensitivityLabelPolicy::updateOrCreate(
                ['policy_id' => $graphPolicy['id']],
                array_merge($parsed, ['synced_at' => now()])
            );
            $syncedIds[] = $policy->id;
        }

        SensitivityLabelPolicy::whereNotIn('id', $syncedIds)->delete();

        return count($syncedIds);
    }

    public function syncSiteLabels(): int
    {
        $sites = $this->fetchSitesFromGraph();
        $syncedIds = [];

        foreach ($sites as $site) {
            $siteLabel = $this->fetchSiteLabelFromGraph($site['id']);
            if (! $siteLabel) {
                continue;
            }

            $label = SensitivityLabel::where('label_id', $siteLabel)->first();
            if (! $label) {
                continue;
            }

            $sharingCapability = $site['sharingCapability'] ?? 'disabled';
            $externalSharing = in_array($sharingCapability, [
                'ExternalUserSharingOnly', 'ExternalUserAndGuestSharing', 'ExistingExternalUserSharingOnly',
            ]);

            $record = SiteSensitivityLabel::updateOrCreate(
                ['site_id' => $site['id']],
                [
                    'site_name' => $site['displayName'] ?? $site['name'] ?? 'Unknown',
                    'site_url' => $site['webUrl'] ?? '',
                    'sensitivity_label_id' => $label->id,
                    'external_sharing_enabled' => $externalSharing,
                    'synced_at' => now(),
                ]
            );
            $syncedIds[] = $record->id;
        }

        SiteSensitivityLabel::whereNotIn('id', $syncedIds)->delete();

        return count($syncedIds);
    }

    public function buildPartnerMappings(): void
    {
        // Clear existing mappings and rebuild
        \DB::table('sensitivity_label_partner')->delete();

        $this->buildPolicyMappings();
        $this->buildSiteMappings();
    }

    public function getUncoveredPartners(): Collection
    {
        return PartnerOrganization::whereDoesntHave('sensitivityLabels')->get();
    }

    private function buildPolicyMappings(): void
    {
        $policies = SensitivityLabelPolicy::all();

        foreach ($policies as $policy) {
            $labelIds = $policy->labels ?? [];
            $labels = SensitivityLabel::whereIn('label_id', $labelIds)->get();

            $partners = match ($policy->target_type) {
                'all_users_and_guests', 'all_users' => PartnerOrganization::all(),
                'specific_groups' => $this->partnersInGroups($policy->target_groups ?? []),
                default => collect(),
            };

            foreach ($labels as $label) {
                $pivotData = [];
                foreach ($partners as $partner) {
                    $pivotData[$partner->id] = [
                        'matched_via' => 'label_policy',
                        'policy_name' => $policy->name,
                    ];
                }
                $label->partners()->syncWithoutDetaching($pivotData);
            }
        }
    }

    private function buildSiteMappings(): void
    {
        $sites = SiteSensitivityLabel::where('external_sharing_enabled', true)
            ->whereNotNull('sensitivity_label_id')
            ->get();

        // For site-based mapping, all partners are potentially impacted
        // if a labeled site has external sharing enabled
        $allPartners = PartnerOrganization::all();

        foreach ($sites as $site) {
            $pivotData = [];
            foreach ($allPartners as $partner) {
                $pivotData[$partner->id] = [
                    'matched_via' => 'site_assignment',
                    'site_name' => $site->site_name,
                ];
            }
            $site->sensitivityLabel->partners()->syncWithoutDetaching($pivotData);
        }
    }

    private function partnersInGroups(array $groupIds): \Illuminate\Support\Collection
    {
        if (empty($groupIds)) {
            return collect();
        }

        // Find partners whose guests are members of the targeted groups
        return PartnerOrganization::whereHas('guestUsers')->get();
    }

    private function fetchLabelsFromGraph(): array
    {
        $response = $this->graph->get('/security/informationProtection/sensitivityLabels');

        return $response['value'] ?? [];
    }

    private function fetchLabelPoliciesFromGraph(): array
    {
        $response = $this->graph->get('/security/informationProtection/sensitivityLabels/policies');

        return $response['value'] ?? [];
    }

    private function fetchSitesFromGraph(): array
    {
        $response = $this->graph->get('/sites', [
            'search' => '*',
            '$select' => 'id,displayName,name,webUrl,sharingCapability',
            '$top' => 999,
        ]);

        return $response['value'] ?? [];
    }

    private function fetchSiteLabelFromGraph(string $siteId): ?string
    {
        try {
            $response = $this->graph->get("/sites/{$siteId}/sensitivityLabel");

            return $response['sensitivityLabelId'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseLabel(array $graphLabel): array
    {
        $contentFormats = $graphLabel['contentFormats'] ?? [];
        $scope = [];
        $hasFileEmail = ! empty(array_intersect(['file', 'email'], $contentFormats));
        $hasSiteGroup = ! empty(array_intersect(['site', 'group', 'schematizedData'], $contentFormats));

        if ($hasFileEmail) {
            $scope[] = 'files_emails';
        }
        if ($hasSiteGroup) {
            $scope[] = 'sites_groups';
        }

        $protectionType = 'none';
        $protection = $graphLabel['protectionSettings'] ?? null;
        if ($protection) {
            if (! empty($protection['encryptionEnabled'])) {
                $protectionType = 'encryption';
            } elseif (! empty($protection['watermarkEnabled']) || ! empty($protection['contentMarking'])) {
                $protectionType = 'watermark';
            } elseif (! empty($protection['headerEnabled']) || ! empty($protection['footerEnabled'])) {
                $protectionType = 'header_footer';
            }
        }

        return [
            'name' => $graphLabel['name'],
            'description' => $graphLabel['description'] ?? null,
            'color' => $graphLabel['color'] ?? null,
            'tooltip' => $graphLabel['tooltip'] ?? null,
            'scope' => $scope,
            'priority' => $graphLabel['priority'] ?? 0,
            'is_active' => $graphLabel['isActive'] ?? true,
            'protection_type' => $protectionType,
            'raw_json' => $graphLabel,
        ];
    }

    private function parsePolicy(array $graphPolicy): array
    {
        $scopes = $graphPolicy['scopes']['users']['included'] ?? [];
        $targetType = 'all_users';

        if (! empty($scopes['allUsersAndGuests'])) {
            $targetType = 'all_users_and_guests';
        } elseif (! empty($scopes['groups'])) {
            $targetType = 'specific_groups';
        }

        $groups = $scopes['groups'] ?? [];

        $labelIds = [];
        foreach (($graphPolicy['settings']['labels'] ?? []) as $labelSetting) {
            if (! empty($labelSetting['labelId'])) {
                $labelIds[] = $labelSetting['labelId'];
            }
        }

        return [
            'name' => $graphPolicy['name'],
            'target_type' => $targetType,
            'target_groups' => $groups,
            'labels' => $labelIds,
            'raw_json' => $graphPolicy,
        ];
    }
}
```

**Step 2: Run tests to verify they pass**

Run: `php artisan test --filter=SensitivityLabelServiceTest`
Expected: All tests PASS.

**Step 3: Commit**

```bash
git add app/Services/SensitivityLabelService.php
git commit -m "feat: implement SensitivityLabelService with Graph API sync"
```

---

### Task 6: Sync Console Command

**Files:**
- Create: `app/Console/Commands/SyncSensitivityLabels.php`
- Modify: `routes/console.php:26-27` (add schedule)

**Step 1: Create the command**

```php
<?php

namespace App\Console\Commands;

use App\Enums\ActivityAction;
use App\Models\SyncLog;
use App\Services\ActivityLogService;
use App\Services\SensitivityLabelService;
use Illuminate\Console\Command;

class SyncSensitivityLabels extends Command
{
    protected $signature = 'sync:sensitivity-labels';

    protected $description = 'Sync sensitivity labels, policies, and site assignments from Microsoft Graph API';

    public function handle(SensitivityLabelService $service): int
    {
        $log = SyncLog::create([
            'type' => 'sensitivity_labels',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Fetching sensitivity labels from Graph API...');
            $labelResult = $service->syncLabels();
            $this->info("Synced {$labelResult['labels_synced']} sensitivity labels.");

            $this->info('Fetching label policies...');
            $policiesSynced = $service->syncPolicies();
            $this->info("Synced {$policiesSynced} label policies.");

            $this->info('Fetching site label assignments...');
            $sitesSynced = $service->syncSiteLabels();
            $this->info("Synced {$sitesSynced} site label assignments.");

            $this->info('Building partner mappings...');
            $service->buildPartnerMappings();

            $totalSynced = $labelResult['labels_synced'] + $policiesSynced + $sitesSynced;

            $log->update([
                'status' => 'completed',
                'records_synced' => $totalSynced,
                'completed_at' => now(),
            ]);

            app(ActivityLogService::class)->logSystem(ActivityAction::SensitivityLabelsSynced, details: [
                'labels_synced' => $labelResult['labels_synced'],
                'policies_synced' => $policiesSynced,
                'sites_synced' => $sitesSynced,
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

**Step 2: Add to schedule**

In `routes/console.php`, add after line 26 (`sync:conditional-access-policies`):

```php
Schedule::command('sync:sensitivity-labels')->cron("*/{$partnersInterval} * * * *");
```

**Step 3: Verify command is registered**

Run: `php artisan list | grep sensitivity`
Expected: `sync:sensitivity-labels` appears in the list.

**Step 4: Commit**

```bash
git add app/Console/Commands/SyncSensitivityLabels.php routes/console.php
git commit -m "feat: add sync:sensitivity-labels command with scheduling"
```

---

### Task 7: Controller Tests (Red)

**Files:**
- Create: `tests/Feature/Controllers/SensitivityLabelControllerTest.php`

**Step 1: Write controller tests**

```php
<?php

use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->actingAs($this->user);
});

test('index page renders with labels', function () {
    SensitivityLabel::create([
        'label_id' => 'l-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'scope' => ['files_emails', 'sites_groups'],
        'is_active' => true,
        'priority' => 2,
        'synced_at' => now(),
    ]);

    $response = $this->get('/sensitivity-labels');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('sensitivity-labels/Index')
        ->has('labels.data', 1)
        ->where('labels.data.0.name', 'Confidential')
        ->has('uncoveredPartnerCount')
    );
});

test('index shows uncovered partner count', function () {
    PartnerOrganization::factory()->count(3)->create();

    $label = SensitivityLabel::create([
        'label_id' => 'l-1',
        'name' => 'Test',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);
    $partner = PartnerOrganization::first();
    $label->partners()->attach($partner->id, [
        'matched_via' => 'label_policy',
        'policy_name' => 'Default',
    ]);

    $response = $this->get('/sensitivity-labels');

    $response->assertInertia(fn ($page) => $page
        ->where('uncoveredPartnerCount', 2)
    );
});

test('show page renders with label and partners', function () {
    $label = SensitivityLabel::create([
        'label_id' => 'l-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'scope' => ['files_emails'],
        'synced_at' => now(),
    ]);
    $partner = PartnerOrganization::factory()->create();
    $label->partners()->attach($partner->id, [
        'matched_via' => 'label_policy',
        'policy_name' => 'Default Policy',
    ]);

    $response = $this->get("/sensitivity-labels/{$label->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('sensitivity-labels/Show')
        ->where('label.name', 'Confidential')
        ->has('label.partners', 1)
    );
});

test('show page includes sub-labels', function () {
    $parent = SensitivityLabel::create([
        'label_id' => 'parent-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);
    SensitivityLabel::create([
        'label_id' => 'child-1',
        'name' => 'Confidential - Internal',
        'protection_type' => 'encryption',
        'parent_label_id' => $parent->id,
        'synced_at' => now(),
    ]);

    $response = $this->get("/sensitivity-labels/{$parent->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('label.children', 1)
    );
});

test('partner show page includes sensitivity labels', function () {
    $partner = PartnerOrganization::factory()->create();
    $label = SensitivityLabel::create([
        'label_id' => 'l-1',
        'name' => 'Secret',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);
    $label->partners()->attach($partner->id, [
        'matched_via' => 'label_policy',
        'policy_name' => 'Default',
    ]);

    $response = $this->get("/partners/{$partner->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('sensitivityLabels', 1)
    );
});

test('viewer role can access sensitivity labels index', function () {
    $viewer = User::factory()->create(['role' => 'viewer', 'approved_at' => now()]);
    $this->actingAs($viewer);

    $response = $this->get('/sensitivity-labels');

    $response->assertOk();
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SensitivityLabelControllerTest`
Expected: FAIL — route not found / controller not found.

**Step 3: Commit**

```bash
git add tests/Feature/Controllers/SensitivityLabelControllerTest.php
git commit -m "test: add sensitivity label controller tests (red)"
```

---

### Task 8: Controller & Routes

**Files:**
- Create: `app/Http/Controllers/SensitivityLabelController.php`
- Modify: `routes/web.php:48-49` (add routes)
- Modify: `app/Http/Controllers/PartnerOrganizationController.php:112-122` (add sensitivity labels to show)

**Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use Inertia\Inertia;
use Inertia\Response;

class SensitivityLabelController extends Controller
{
    public function index(): Response
    {
        $labels = SensitivityLabel::withCount('partners')
            ->whereNull('parent_label_id')
            ->orderBy('priority')
            ->paginate(25);

        $uncoveredPartnerCount = PartnerOrganization::whereDoesntHave('sensitivityLabels')->count();

        return Inertia::render('sensitivity-labels/Index', [
            'labels' => $labels,
            'uncoveredPartnerCount' => $uncoveredPartnerCount,
        ]);
    }

    public function show(SensitivityLabel $sensitivityLabel): Response
    {
        $sensitivityLabel->load(['partners', 'children']);

        return Inertia::render('sensitivity-labels/Show', [
            'label' => $sensitivityLabel,
        ]);
    }
}
```

**Step 2: Add routes**

In `routes/web.php`, add after the conditional access routes (after line 49), and add the use statement at the top:

Add to imports:
```php
use App\Http\Controllers\SensitivityLabelController;
```

Add routes:
```php
Route::get('sensitivity-labels', [SensitivityLabelController::class, 'index'])->name('sensitivity-labels.index');
Route::get('sensitivity-labels/{sensitivityLabel}', [SensitivityLabelController::class, 'show'])->name('sensitivity-labels.show');
```

**Step 3: Add sensitivity labels to partner show**

In `app/Http/Controllers/PartnerOrganizationController.php`, after line 114 (the `$conditionalAccessPolicies` block), add:

```php
$sensitivityLabels = $partner->sensitivityLabels()
    ->withPivot('matched_via', 'policy_name', 'site_name')
    ->get();
```

And add `'sensitivityLabels' => $sensitivityLabels,` to the Inertia render props (after `'conditionalAccessPolicies'`).

**Step 4: Run controller tests**

Run: `php artisan test --filter=SensitivityLabelControllerTest`
Expected: Tests that don't need Vue pages will pass; Inertia component assertions may fail until frontend is created. The route-level tests should pass.

**Step 5: Commit**

```bash
git add app/Http/Controllers/SensitivityLabelController.php routes/web.php app/Http/Controllers/PartnerOrganizationController.php
git commit -m "feat: add sensitivity label controller and routes"
```

---

### Task 9: TypeScript Types

**Files:**
- Create: `resources/js/types/sensitivity-label.ts`
- Modify: `resources/js/types/index.ts:5` (add export)

**Step 1: Create type definitions**

```typescript
import type { PartnerOrganization } from './partner';

export type SensitivityLabel = {
    id: number;
    label_id: string;
    name: string;
    description: string | null;
    color: string | null;
    tooltip: string | null;
    scope: ('files_emails' | 'sites_groups')[] | null;
    priority: number;
    is_active: boolean;
    parent_label_id: number | null;
    protection_type: 'encryption' | 'watermark' | 'header_footer' | 'none';
    synced_at: string | null;
    partners_count?: number;
    partners?: (PartnerOrganization & {
        pivot: {
            matched_via: 'label_policy' | 'site_assignment';
            policy_name: string | null;
            site_name: string | null;
        };
    })[];
    children?: SensitivityLabel[];
};
```

**Step 2: Add export to barrel file**

In `resources/js/types/index.ts`, add:

```typescript
export * from './sensitivity-label';
```

**Step 3: Run type check**

Run: `npm run types:check`
Expected: No type errors.

**Step 4: Commit**

```bash
git add resources/js/types/sensitivity-label.ts resources/js/types/index.ts
git commit -m "feat: add sensitivity label TypeScript types"
```

---

### Task 10: Frontend — Index Page

**Files:**
- Create: `resources/js/pages/sensitivity-labels/Index.vue`

**Step 1: Create the index page**

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
import type { SensitivityLabel } from '@/types/sensitivity-label';
import type { Paginated } from '@/types/partner';

defineProps<{
    labels: Paginated<SensitivityLabel>;
    uncoveredPartnerCount: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Sensitivity Labels', href: '/sensitivity-labels' },
];

function protectionVariant(
    type: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<
        string,
        'default' | 'destructive' | 'outline' | 'secondary'
    > = {
        encryption: 'default',
        watermark: 'secondary',
        header_footer: 'secondary',
        none: 'outline',
    };
    return map[type] ?? 'outline';
}

function protectionLabel(type: string): string {
    const map: Record<string, string> = {
        encryption: 'Encryption',
        watermark: 'Watermark',
        header_footer: 'Header/Footer',
        none: 'No protection',
    };
    return map[type] ?? type;
}

function formatScope(scope: string[] | null): string {
    if (!scope || scope.length === 0) return '\u2014';
    const labels: Record<string, string> = {
        files_emails: 'Files & Emails',
        sites_groups: 'Sites & Groups',
    };
    return scope.map((s) => labels[s] ?? s).join(', ');
}
</script>

<template>
    <Head title="Sensitivity Labels" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div>
                <h1 class="text-2xl font-semibold">Sensitivity Labels</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Microsoft Information Protection sensitivity labels and
                    their impact on partner organizations.
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
                    partner{{ uncoveredPartnerCount === 1 ? '' : 's' }} ha{{
                        uncoveredPartnerCount === 1 ? 's' : 've'
                    }}
                    no sensitivity label coverage.
                </p>
            </div>

            <Card v-if="labels.data.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No sensitivity labels found. Run the sync command or wait
                    for the next scheduled sync.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Label</TableHead>
                        <TableHead>Protection</TableHead>
                        <TableHead>Scope</TableHead>
                        <TableHead>Priority</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Affected Partners</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="label in labels.data" :key="label.id">
                        <TableCell>
                            <Link
                                :href="`/sensitivity-labels/${label.id}`"
                                class="font-medium hover:underline"
                            >
                                <span
                                    v-if="label.color"
                                    class="mr-2 inline-block size-3 rounded-full"
                                    :style="{ backgroundColor: label.color }"
                                />
                                {{ label.name }}
                            </Link>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    protectionVariant(label.protection_type)
                                "
                            >
                                {{ protectionLabel(label.protection_type) }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ formatScope(label.scope) }}</TableCell>
                        <TableCell>{{ label.priority }}</TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    label.is_active ? 'default' : 'outline'
                                "
                            >
                                {{ label.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{
                            label.partners_count ?? 0
                        }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/sensitivity-labels/Index.vue
git commit -m "feat: add sensitivity labels index page"
```

---

### Task 11: Frontend — Show Page

**Files:**
- Create: `resources/js/pages/sensitivity-labels/Show.vue`

**Step 1: Create the show page**

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
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
import type { SensitivityLabel } from '@/types/sensitivity-label';

const props = defineProps<{
    label: SensitivityLabel;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Sensitivity Labels', href: '/sensitivity-labels' },
    {
        title: props.label.name,
        href: `/sensitivity-labels/${props.label.id}`,
    },
];

function protectionLabel(type: string): string {
    const map: Record<string, string> = {
        encryption: 'Encryption',
        watermark: 'Watermark',
        header_footer: 'Header/Footer',
        none: 'No protection',
    };
    return map[type] ?? type;
}

function protectionVariant(
    type: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<
        string,
        'default' | 'destructive' | 'outline' | 'secondary'
    > = {
        encryption: 'default',
        watermark: 'secondary',
        header_footer: 'secondary',
        none: 'outline',
    };
    return map[type] ?? 'outline';
}

function formatScope(scope: string[] | null): string {
    if (!scope || scope.length === 0) return '\u2014';
    const labels: Record<string, string> = {
        files_emails: 'Files & Emails',
        sites_groups: 'Sites & Groups',
    };
    return scope.map((s) => labels[s] ?? s).join(', ');
}

function matchedViaLabel(via: string): string {
    return via === 'label_policy' ? 'Label Policy' : 'Site Assignment';
}
</script>

<template>
    <Head :title="label.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div>
                <div class="flex items-center gap-3">
                    <span
                        v-if="label.color"
                        class="inline-block size-4 rounded-full"
                        :style="{ backgroundColor: label.color }"
                    />
                    <h1 class="text-2xl font-semibold">
                        {{ label.name }}
                    </h1>
                    <Badge
                        :variant="label.is_active ? 'default' : 'outline'"
                    >
                        {{ label.is_active ? 'Active' : 'Inactive' }}
                    </Badge>
                </div>
                <p
                    v-if="label.description"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ label.description }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    Label ID: {{ label.label_id }}
                </p>
            </div>

            <Separator />

            <Card>
                <CardHeader>
                    <CardTitle>Label Details</CardTitle>
                </CardHeader>
                <CardContent
                    class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm"
                >
                    <span class="text-muted-foreground">Protection</span>
                    <span>
                        <Badge
                            :variant="
                                protectionVariant(label.protection_type)
                            "
                        >
                            {{ protectionLabel(label.protection_type) }}
                        </Badge>
                    </span>

                    <span class="text-muted-foreground">Scope</span>
                    <span>{{ formatScope(label.scope) }}</span>

                    <span class="text-muted-foreground">Priority</span>
                    <span>{{ label.priority }}</span>

                    <span
                        v-if="label.tooltip"
                        class="text-muted-foreground"
                        >Tooltip</span
                    >
                    <span v-if="label.tooltip">{{ label.tooltip }}</span>
                </CardContent>
            </Card>

            <Card v-if="label.children?.length">
                <CardHeader>
                    <CardTitle
                        >Sub-Labels ({{
                            label.children.length
                        }})</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Protection</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="child in label.children"
                                :key="child.id"
                            >
                                <TableCell>
                                    <Link
                                        :href="`/sensitivity-labels/${child.id}`"
                                        class="font-medium hover:underline"
                                    >
                                        {{ child.name }}
                                    </Link>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            protectionVariant(
                                                child.protection_type,
                                            )
                                        "
                                    >
                                        {{
                                            protectionLabel(
                                                child.protection_type,
                                            )
                                        }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        :variant="
                                            child.is_active
                                                ? 'default'
                                                : 'outline'
                                        "
                                    >
                                        {{
                                            child.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle
                        >Affected Partners ({{
                            label.partners?.length ?? 0
                        }})</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <Table v-if="label.partners?.length">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Partner</TableHead>
                                <TableHead>Domain</TableHead>
                                <TableHead>Matched Via</TableHead>
                                <TableHead>Source</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="partner in label.partners"
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
                                        {{
                                            matchedViaLabel(
                                                partner.pivot.matched_via,
                                            )
                                        }}
                                    </Badge>
                                </TableCell>
                                <TableCell class="text-muted-foreground">
                                    {{
                                        partner.pivot.policy_name ??
                                        partner.pivot.site_name ??
                                        '\u2014'
                                    }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p
                        v-else
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No partners matched by this label.
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/sensitivity-labels/Show.vue
git commit -m "feat: add sensitivity labels show page"
```

---

### Task 12: Partner Detail Integration

**Files:**
- Modify: `resources/js/pages/partners/Show.vue:48-55` (add props + section)

**Step 1: Update partner Show.vue**

Add to the imports (after the ConditionalAccessPolicy import on line 41):
```typescript
import type { SensitivityLabel } from '@/types/sensitivity-label';
```

Add to the `import { ... } from 'lucide-vue-next'` (line 3), add `Tag` to the imports:
```typescript
import { AlertTriangle, CircleHelp, Shield, Tag } from 'lucide-vue-next';
```

Add to defineProps (after `conditionalAccessPolicies` on line 52-55):
```typescript
sensitivityLabels: (SensitivityLabel & {
    pivot: {
        matched_via: 'label_policy' | 'site_assignment';
        policy_name: string | null;
        site_name: string | null;
    };
})[];
```

Add a new Card section after the Conditional Access Card (after the `</Card>` closing tag around line 729, before the Guest Users Card):

```vue
<!-- Sensitivity Labels -->
<Card>
    <CardHeader>
        <CardTitle class="flex items-center gap-2">
            <Tag class="size-5" />
            Sensitivity Labels ({{ sensitivityLabels.length }})
        </CardTitle>
    </CardHeader>
    <CardContent>
        <div
            v-if="sensitivityLabels.length === 0"
            class="flex items-center gap-3 rounded-lg border border-yellow-300 bg-yellow-50 p-4 dark:border-yellow-700 dark:bg-yellow-950"
        >
            <AlertTriangle
                class="size-5 shrink-0 text-yellow-600 dark:text-yellow-400"
            />
            <p class="text-sm text-yellow-800 dark:text-yellow-200">
                No sensitivity labels cover this partner's guests.
                <Link
                    href="/sensitivity-labels"
                    class="underline"
                    >View all labels</Link
                >
            </p>
        </div>
        <Table v-else>
            <TableHeader>
                <TableRow>
                    <TableHead>Label</TableHead>
                    <TableHead>Protection</TableHead>
                    <TableHead>Matched Via</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="label in sensitivityLabels"
                    :key="label.id"
                >
                    <TableCell>
                        <Link
                            :href="`/sensitivity-labels/${label.id}`"
                            class="font-medium hover:underline"
                        >
                            <span
                                v-if="label.color"
                                class="mr-2 inline-block size-3 rounded-full"
                                :style="{
                                    backgroundColor: label.color,
                                }"
                            />
                            {{ label.name }}
                        </Link>
                    </TableCell>
                    <TableCell>
                        <Badge
                            :variant="
                                label.protection_type === 'encryption'
                                    ? 'default'
                                    : label.protection_type === 'none'
                                      ? 'outline'
                                      : 'secondary'
                            "
                        >
                            {{
                                {
                                    encryption: 'Encryption',
                                    watermark: 'Watermark',
                                    header_footer: 'Header/Footer',
                                    none: 'No protection',
                                }[label.protection_type] ??
                                label.protection_type
                            }}
                        </Badge>
                    </TableCell>
                    <TableCell>
                        <Badge variant="secondary">
                            {{
                                label.pivot.matched_via ===
                                'label_policy'
                                    ? 'Label Policy'
                                    : 'Site Assignment'
                            }}
                        </Badge>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </CardContent>
</Card>
```

**Step 2: Run type check**

Run: `npm run types:check`
Expected: No type errors.

**Step 3: Commit**

```bash
git add resources/js/pages/partners/Show.vue
git commit -m "feat: add sensitivity labels section to partner detail page"
```

---

### Task 13: Sidebar Navigation

**Files:**
- Modify: `resources/js/components/AppSidebar.vue:46-49` (add nav item)

**Step 1: Add Tag icon import**

In `resources/js/components/AppSidebar.vue`, add `Tag` to the lucide-vue-next imports (line 3-14):

```typescript
import {
    Activity,
    BarChart3,
    Building2,
    ClipboardCheck,
    FileStack,
    LayoutGrid,
    Package,
    Settings,
    Shield,
    Tag,
    Users,
} from 'lucide-vue-next';
```

**Step 2: Add nav item**

In the `mainNavItems` computed (after the Conditional Access item on line 46-49), add:

```typescript
{
    title: 'Sensitivity Labels',
    href: '/sensitivity-labels',
    icon: Tag,
},
```

**Step 3: Commit**

```bash
git add resources/js/components/AppSidebar.vue
git commit -m "feat: add sensitivity labels to sidebar navigation"
```

---

### Task 14: Compliance Report Integration

**Files:**
- Modify: `app/Http/Controllers/ComplianceReportController.php:29-33,77-81,83-85,96-101,117,129`

**Step 1: Add sensitivity label compliance to index method**

In `ComplianceReportController.php`, after the `$noCaPolicies` block (after line 33), add:

```php
$partnersWithSensitivityLabels = DB::table('sensitivity_label_partner')
    ->distinct()
    ->pluck('partner_organization_id');
$noSensitivityLabels = PartnerOrganization::whereNotIn('id', $partnersWithSensitivityLabels)
    ->get(['id', 'display_name', 'domain', 'tenant_id']);
```

Update the `$nonCompliantIds` merge chain (around line 80) to add:

```php
->merge($noSensitivityLabels->pluck('id'))
```

Update the `$nonCompliantPartners` query (around line 84) to add `withCount`:

```php
$nonCompliantPartners = PartnerOrganization::whereIn('id', $nonCompliantIds)
    ->withCount(['conditionalAccessPolicies', 'sensitivityLabels'])
    ->get(['id', 'display_name', 'domain', 'mfa_trust_enabled', 'device_trust_enabled', 'b2b_inbound_enabled', 'b2b_outbound_enabled', 'trust_score']);
```

Add to the `partnerCompliance` array in the Inertia render:

```php
'no_sensitivity_labels_count' => $noSensitivityLabels->count(),
```

**Step 2: Update export method**

In the `export()` method, update the partners query (around line 117):

```php
$partners = PartnerOrganization::withCount(['conditionalAccessPolicies', 'sensitivityLabels'])
    ->orderBy('display_name')
    ->get();
```

Update the CSV header row (around line 129) to add `Sensitivity Label Count`:

```php
fputcsv($handle, ['Partner Name', 'Domain', 'MFA Trust', 'Device Trust', 'B2B Inbound', 'B2B Outbound', 'Trust Score', 'CA Policy Count', 'Sensitivity Label Count']);
```

Add `$partner->sensitivity_labels_count` to each row's data array.

**Step 3: Run all tests**

Run: `php artisan test`
Expected: All tests pass.

**Step 4: Commit**

```bash
git add app/Http/Controllers/ComplianceReportController.php
git commit -m "feat: integrate sensitivity labels into compliance reporting"
```

---

### Task 15: Run Full CI Check & Fix Issues

**Step 1: Run lint**

Run: `composer run lint`

**Step 2: Run frontend lint + format**

Run: `npm run lint && npm run format`

**Step 3: Run type check**

Run: `npm run types:check`

**Step 4: Run full test suite**

Run: `composer run ci:check`
Expected: All checks pass.

**Step 5: Fix any issues found, then commit**

```bash
git add -A
git commit -m "fix: address lint and format issues for sensitivity labels"
```

---

### Task 16: Final Verification

**Step 1: Run the full CI check one more time**

Run: `composer run ci:check`
Expected: All checks pass clean.

**Step 2: Verify routes are registered**

Run: `php artisan route:list | grep sensitivity`
Expected:
```
GET  sensitivity-labels .............. sensitivity-labels.index
GET  sensitivity-labels/{sensitivityLabel} ... sensitivity-labels.show
```

**Step 3: Verify migration runs clean**

Run: `php artisan migrate:fresh && php artisan test`
Expected: All migrations run, all tests pass.

**Step 4: Commit any remaining fixes**

```bash
git add -A
git commit -m "chore: final cleanup for sensitivity label feature"
```
