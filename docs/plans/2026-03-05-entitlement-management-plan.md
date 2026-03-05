# Entitlement Management Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build self-service access packages for external partner users, with Graph API integration, single-stage approval, configurable expiration, and background sync.

**Architecture:** Graph-first with local cache. Write-through to Graph API on create/update/delete, cache locally in 4 new tables (catalogs, packages, resources, assignments). Background sync reconciles every 15 minutes. Follows existing partner/guest sync pattern.

**Tech Stack:** Laravel 12 (PHP 8.2), Vue 3 + TypeScript + Inertia.js, Microsoft Graph API, Pest PHP, shadcn-vue, Tailwind CSS.

---

## Task 1: Database Migrations

**Files:**
- Create: `database/migrations/2026_03_05_100001_create_access_package_catalogs_table.php`
- Create: `database/migrations/2026_03_05_100002_create_access_packages_table.php`
- Create: `database/migrations/2026_03_05_100003_create_access_package_resources_table.php`
- Create: `database/migrations/2026_03_05_100004_create_access_package_assignments_table.php`

**Step 1: Create the four migration files**

```php
// database/migrations/2026_03_05_100001_create_access_package_catalogs_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_package_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('graph_id')->nullable()->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_package_catalogs');
    }
};
```

```php
// database/migrations/2026_03_05_100002_create_access_packages_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_packages', function (Blueprint $table) {
            $table->id();
            $table->string('graph_id')->nullable()->unique();
            $table->foreignId('catalog_id')->constrained('access_package_catalogs');
            $table->foreignId('partner_organization_id')->constrained('partner_organizations')->cascadeOnDelete();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_days')->default(90);
            $table->boolean('approval_required')->default(true);
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_packages');
    }
};
```

```php
// database/migrations/2026_03_05_100003_create_access_package_resources_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_package_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_package_id')->constrained('access_packages')->cascadeOnDelete();
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('resource_display_name');
            $table->string('graph_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_package_resources');
    }
};
```

```php
// database/migrations/2026_03_05_100004_create_access_package_assignments_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_package_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('graph_id')->nullable()->unique();
            $table->foreignId('access_package_id')->constrained('access_packages')->cascadeOnDelete();
            $table->string('target_user_email');
            $table->string('target_user_id')->nullable();
            $table->string('status')->default('pending_approval');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('justification')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_package_assignments');
    }
};
```

**Step 2: Run migrations**

Run: `php artisan migrate`
Expected: All four tables created successfully.

**Step 3: Commit**

```bash
git add database/migrations/2026_03_05_10000*.php
git commit -m "feat(entitlements): add database migrations for access packages"
```

---

## Task 2: Enums

**Files:**
- Create: `app/Enums/AccessPackageResourceType.php`
- Create: `app/Enums/AssignmentStatus.php`
- Modify: `app/Enums/ActivityAction.php`

**Step 1: Create AccessPackageResourceType enum**

```php
<?php

namespace App\Enums;

enum AccessPackageResourceType: string
{
    case Group = 'group';
    case SharePointSite = 'sharepoint_site';
}
```

**Step 2: Create AssignmentStatus enum**

```php
<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Denied = 'denied';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
```

**Step 3: Add entitlement activity actions to ActivityAction enum**

Add these cases to `app/Enums/ActivityAction.php` after the existing access review cases:

```php
    case AccessPackageCreated = 'access_package_created';
    case AccessPackageUpdated = 'access_package_updated';
    case AccessPackageDeleted = 'access_package_deleted';
    case AssignmentRequested = 'assignment_requested';
    case AssignmentApproved = 'assignment_approved';
    case AssignmentDenied = 'assignment_denied';
    case AssignmentRevoked = 'assignment_revoked';
```

**Step 4: Commit**

```bash
git add app/Enums/AccessPackageResourceType.php app/Enums/AssignmentStatus.php app/Enums/ActivityAction.php
git commit -m "feat(entitlements): add enums for resource types, assignment statuses, and activity actions"
```

---

## Task 3: Eloquent Models

**Files:**
- Create: `app/Models/AccessPackageCatalog.php`
- Create: `app/Models/AccessPackage.php`
- Create: `app/Models/AccessPackageResource.php`
- Create: `app/Models/AccessPackageAssignment.php`

**Step 1: Create AccessPackageCatalog model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessPackageCatalog extends Model
{
    use HasFactory;

    protected $fillable = [
        'graph_id', 'display_name', 'description', 'is_default', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function accessPackages(): HasMany
    {
        return $this->hasMany(AccessPackage::class, 'catalog_id');
    }
}
```

**Step 2: Create AccessPackage model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'graph_id', 'catalog_id', 'partner_organization_id', 'display_name',
        'description', 'duration_days', 'approval_required', 'approver_user_id',
        'is_active', 'created_by_user_id', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'approval_required' => 'boolean',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(AccessPackageCatalog::class, 'catalog_id');
    }

    public function partnerOrganization(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(AccessPackageResource::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AccessPackageAssignment::class);
    }
}
```

**Step 3: Create AccessPackageResource model**

```php
<?php

namespace App\Models;

use App\Enums\AccessPackageResourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessPackageResource extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_package_id', 'resource_type', 'resource_id',
        'resource_display_name', 'graph_id',
    ];

    protected function casts(): array
    {
        return [
            'resource_type' => AccessPackageResourceType::class,
        ];
    }

    public function accessPackage(): BelongsTo
    {
        return $this->belongsTo(AccessPackage::class);
    }
}
```

**Step 4: Create AccessPackageAssignment model**

```php
<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessPackageAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'graph_id', 'access_package_id', 'target_user_email', 'target_user_id',
        'status', 'approved_by_user_id', 'expires_at', 'requested_at',
        'approved_at', 'delivered_at', 'justification', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'expires_at' => 'datetime',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function accessPackage(): BelongsTo
    {
        return $this->belongsTo(AccessPackage::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
```

**Step 5: Commit**

```bash
git add app/Models/AccessPackageCatalog.php app/Models/AccessPackage.php app/Models/AccessPackageResource.php app/Models/AccessPackageAssignment.php
git commit -m "feat(entitlements): add Eloquent models for access packages"
```

---

## Task 4: Model Factories

**Files:**
- Create: `database/factories/AccessPackageCatalogFactory.php`
- Create: `database/factories/AccessPackageFactory.php`
- Create: `database/factories/AccessPackageResourceFactory.php`
- Create: `database/factories/AccessPackageAssignmentFactory.php`

**Step 1: Create all four factories**

```php
// database/factories/AccessPackageCatalogFactory.php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AccessPackageCatalogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'graph_id' => fake()->optional()->uuid(),
            'display_name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'is_default' => false,
        ];
    }
}
```

```php
// database/factories/AccessPackageFactory.php
<?php

namespace Database\Factories;

use App\Models\AccessPackageCatalog;
use App\Models\PartnerOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessPackageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'graph_id' => fake()->optional()->uuid(),
            'catalog_id' => AccessPackageCatalog::factory(),
            'partner_organization_id' => PartnerOrganization::factory(),
            'display_name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'duration_days' => fake()->randomElement([30, 60, 90, 180]),
            'approval_required' => true,
            'approver_user_id' => User::factory(),
            'is_active' => true,
            'created_by_user_id' => User::factory(),
        ];
    }
}
```

```php
// database/factories/AccessPackageResourceFactory.php
<?php

namespace Database\Factories;

use App\Enums\AccessPackageResourceType;
use App\Models\AccessPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessPackageResourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'access_package_id' => AccessPackage::factory(),
            'resource_type' => fake()->randomElement(AccessPackageResourceType::cases()),
            'resource_id' => fake()->uuid(),
            'resource_display_name' => fake()->words(2, true),
            'graph_id' => fake()->optional()->uuid(),
        ];
    }
}
```

```php
// database/factories/AccessPackageAssignmentFactory.php
<?php

namespace Database\Factories;

use App\Enums\AssignmentStatus;
use App\Models\AccessPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessPackageAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'graph_id' => fake()->optional()->uuid(),
            'access_package_id' => AccessPackage::factory(),
            'target_user_email' => fake()->safeEmail(),
            'target_user_id' => fake()->optional()->uuid(),
            'status' => fake()->randomElement(AssignmentStatus::cases()),
            'requested_at' => now(),
            'justification' => fake()->optional()->sentence(),
        ];
    }
}
```

**Step 2: Commit**

```bash
git add database/factories/AccessPackageCatalogFactory.php database/factories/AccessPackageFactory.php database/factories/AccessPackageResourceFactory.php database/factories/AccessPackageAssignmentFactory.php
git commit -m "feat(entitlements): add model factories for test data"
```

---

## Task 5: EntitlementService — Core Operations

**Files:**
- Create: `app/Services/EntitlementService.php`
- Create: `tests/Feature/EntitlementServiceTest.php`

**Step 1: Write the failing tests**

```php
// tests/Feature/EntitlementServiceTest.php
<?php

use App\Enums\AccessPackageResourceType;
use App\Enums\AssignmentStatus;
use App\Models\AccessPackage;
use App\Models\AccessPackageAssignment;
use App\Models\AccessPackageCatalog;
use App\Models\AccessPackageResource;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\EntitlementService;
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
});

test('getOrCreateDefaultCatalog fetches existing default catalog from Graph', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/catalogs*' => Http::response([
            'value' => [
                ['id' => 'catalog-123', 'displayName' => 'General', 'isExternallyVisible' => true],
            ],
        ]),
    ]);

    $service = app(EntitlementService::class);
    $catalog = $service->getOrCreateDefaultCatalog();

    expect($catalog)->toBeInstanceOf(AccessPackageCatalog::class);
    expect($catalog->graph_id)->toBe('catalog-123');
    expect($catalog->display_name)->toBe('General');
    expect(AccessPackageCatalog::count())->toBe(1);
});

test('createAccessPackage creates package in Graph API and local DB', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/accessPackages' => Http::response([
            'id' => 'pkg-graph-123',
            'displayName' => 'Partner Dev Access',
        ], 201),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/assignmentPolicies' => Http::response([
            'id' => 'policy-123',
        ], 201),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/connectedOrganizations*' => Http::response([
            'value' => [],
        ]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/connectedOrganizations' => Http::response([
            'id' => 'conn-org-123',
        ], 201),
    ]);

    $catalog = AccessPackageCatalog::factory()->create(['graph_id' => 'catalog-123']);
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'partner-tenant-id']);
    $admin = User::factory()->create(['role' => 'admin']);
    $approver = User::factory()->create(['role' => 'operator']);

    $service = app(EntitlementService::class);
    $package = $service->createAccessPackage($catalog, $partner, [
        'display_name' => 'Partner Dev Access',
        'description' => 'Development resources',
        'duration_days' => 90,
        'approval_required' => true,
        'approver_user_id' => $approver->id,
        'created_by_user_id' => $admin->id,
    ]);

    expect($package)->toBeInstanceOf(AccessPackage::class);
    expect($package->graph_id)->toBe('pkg-graph-123');
    expect($package->display_name)->toBe('Partner Dev Access');
    expect($package->partner_organization_id)->toBe($partner->id);
    expect(AccessPackage::count())->toBe(1);
});

test('deleteAccessPackage removes from Graph API and local DB', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/accessPackages/*' => Http::response([], 204),
    ]);

    $package = AccessPackage::factory()->create(['graph_id' => 'pkg-graph-456']);

    $service = app(EntitlementService::class);
    $service->deleteAccessPackage($package);

    expect(AccessPackage::count())->toBe(0);
});

test('addResource adds resource role scope to package in Graph', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/accessPackages/*/accessPackageResourceRoleScopes' => Http::response([
            'id' => 'role-scope-123',
        ], 201),
    ]);

    $package = AccessPackage::factory()->create(['graph_id' => 'pkg-graph-789']);

    $service = app(EntitlementService::class);
    $resource = $service->addResource($package, [
        'resource_type' => 'group',
        'resource_id' => 'group-abc-123',
        'resource_display_name' => 'Dev Team',
    ]);

    expect($resource)->toBeInstanceOf(AccessPackageResource::class);
    expect($resource->resource_type)->toBe(AccessPackageResourceType::Group);
    expect($resource->resource_display_name)->toBe('Dev Team');
    expect(AccessPackageResource::count())->toBe(1);
});

test('requestAssignment creates assignment request in Graph', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/assignmentRequests' => Http::response([
            'id' => 'request-123',
            'status' => 'PendingApproval',
        ], 201),
    ]);

    $package = AccessPackage::factory()->create(['graph_id' => 'pkg-graph-111', 'duration_days' => 90]);

    $service = app(EntitlementService::class);
    $assignment = $service->requestAssignment($package, 'partner@external.com', 'Need access for project');

    expect($assignment)->toBeInstanceOf(AccessPackageAssignment::class);
    expect($assignment->target_user_email)->toBe('partner@external.com');
    expect($assignment->status)->toBe(AssignmentStatus::PendingApproval);
    expect($assignment->justification)->toBe('Need access for project');
    expect(AccessPackageAssignment::count())->toBe(1);
});

test('approveAssignment updates status and records approver', function () {
    $assignment = AccessPackageAssignment::factory()->create([
        'status' => AssignmentStatus::PendingApproval,
    ]);
    $operator = User::factory()->create(['role' => 'operator']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response([], 200),
    ]);

    $service = app(EntitlementService::class);
    $service->approveAssignment($assignment, $operator);

    $assignment->refresh();
    expect($assignment->status)->toBe(AssignmentStatus::Approved);
    expect($assignment->approved_by_user_id)->toBe($operator->id);
    expect($assignment->approved_at)->not->toBeNull();
});

test('denyAssignment updates status', function () {
    $assignment = AccessPackageAssignment::factory()->create([
        'status' => AssignmentStatus::PendingApproval,
    ]);
    $operator = User::factory()->create(['role' => 'operator']);

    $service = app(EntitlementService::class);
    $service->denyAssignment($assignment, $operator);

    $assignment->refresh();
    expect($assignment->status)->toBe(AssignmentStatus::Denied);
});

test('revokeAssignment updates status to revoked', function () {
    $assignment = AccessPackageAssignment::factory()->create([
        'status' => AssignmentStatus::Delivered,
        'graph_id' => 'assign-graph-123',
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/entitlementManagement/assignmentRequests' => Http::response([
            'id' => 'revoke-request-123',
        ], 201),
    ]);

    $service = app(EntitlementService::class);
    $service->revokeAssignment($assignment);

    $assignment->refresh();
    expect($assignment->status)->toBe(AssignmentStatus::Revoked);
});

test('listGroups returns groups from Graph API', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/groups*' => Http::response([
            'value' => [
                ['id' => 'grp-1', 'displayName' => 'Dev Team', 'description' => 'Developers'],
                ['id' => 'grp-2', 'displayName' => 'QA Team', 'description' => 'Quality Assurance'],
            ],
        ]),
    ]);

    $service = app(EntitlementService::class);
    $groups = $service->listGroups();

    expect($groups)->toHaveCount(2);
    expect($groups[0]['displayName'])->toBe('Dev Team');
});

test('listSharePointSites returns sites from Graph API', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/sites*' => Http::response([
            'value' => [
                ['id' => 'site-1', 'displayName' => 'Project Docs', 'webUrl' => 'https://contoso.sharepoint.com/sites/docs'],
            ],
        ]),
    ]);

    $service = app(EntitlementService::class);
    $sites = $service->listSharePointSites();

    expect($sites)->toHaveCount(1);
    expect($sites[0]['displayName'])->toBe('Project Docs');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=EntitlementServiceTest`
Expected: FAIL — EntitlementService class not found.

**Step 3: Write EntitlementService**

```php
// app/Services/EntitlementService.php
<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Models\AccessPackage;
use App\Models\AccessPackageAssignment;
use App\Models\AccessPackageCatalog;
use App\Models\AccessPackageResource;
use App\Models\PartnerOrganization;
use App\Models\User;

class EntitlementService
{
    public function __construct(
        private MicrosoftGraphService $graph,
    ) {}

    public function getOrCreateDefaultCatalog(): AccessPackageCatalog
    {
        $response = $this->graph->get('/identityGovernance/entitlementManagement/catalogs', [
            '$filter' => "displayName eq 'General'",
        ]);

        $catalogData = $response['value'][0] ?? null;

        if (! $catalogData) {
            $catalogData = $this->graph->post('/identityGovernance/entitlementManagement/catalogs', [
                'displayName' => 'General',
                'description' => 'Default catalog for Partner365 access packages',
                'isExternallyVisible' => true,
            ]);
        }

        return AccessPackageCatalog::updateOrCreate(
            ['graph_id' => $catalogData['id']],
            [
                'display_name' => $catalogData['displayName'],
                'is_default' => true,
                'last_synced_at' => now(),
            ]
        );
    }

    public function createAccessPackage(AccessPackageCatalog $catalog, PartnerOrganization $partner, array $data): AccessPackage
    {
        $this->ensureConnectedOrganization($partner);

        $graphResponse = $this->graph->post('/identityGovernance/entitlementManagement/accessPackages', [
            'displayName' => $data['display_name'],
            'description' => $data['description'] ?? '',
            'catalog' => ['id' => $catalog->graph_id],
            'isHidden' => false,
        ]);

        $package = AccessPackage::create([
            ...$data,
            'graph_id' => $graphResponse['id'] ?? null,
            'catalog_id' => $catalog->id,
            'partner_organization_id' => $partner->id,
        ]);

        if ($data['approval_required'] ?? true) {
            $this->createAssignmentPolicy($package, $data['approver_user_id'] ?? null);
        }

        return $package;
    }

    public function updateAccessPackage(AccessPackage $package, array $data): AccessPackage
    {
        if ($package->graph_id) {
            $this->graph->patch("/identityGovernance/entitlementManagement/accessPackages/{$package->graph_id}", [
                'displayName' => $data['display_name'] ?? $package->display_name,
                'description' => $data['description'] ?? $package->description ?? '',
            ]);
        }

        $package->update($data);

        return $package->fresh();
    }

    public function deleteAccessPackage(AccessPackage $package): void
    {
        if ($package->graph_id) {
            $this->graph->delete("/identityGovernance/entitlementManagement/accessPackages/{$package->graph_id}");
        }

        $package->delete();
    }

    public function addResource(AccessPackage $package, array $data): AccessPackageResource
    {
        $graphId = null;

        if ($package->graph_id) {
            $originSystem = $data['resource_type'] === 'sharepoint_site' ? 'SharePointOnline' : 'AadGroup';

            $graphResponse = $this->graph->post(
                "/identityGovernance/entitlementManagement/accessPackages/{$package->graph_id}/accessPackageResourceRoleScopes",
                [
                    'accessPackageResourceRole' => [
                        'originId' => 'Member',
                        'originSystem' => $originSystem,
                        'accessPackageResource' => [
                            'originId' => $data['resource_id'],
                            'originSystem' => $originSystem,
                        ],
                    ],
                    'accessPackageResourceScope' => [
                        'originId' => $data['resource_id'],
                        'originSystem' => $originSystem,
                    ],
                ]
            );

            $graphId = $graphResponse['id'] ?? null;
        }

        return AccessPackageResource::create([
            'access_package_id' => $package->id,
            'resource_type' => $data['resource_type'],
            'resource_id' => $data['resource_id'],
            'resource_display_name' => $data['resource_display_name'],
            'graph_id' => $graphId,
        ]);
    }

    public function removeResource(AccessPackageResource $resource): void
    {
        // Graph API removes resource role scopes when the resource is removed from the catalog
        // For now, just remove locally — sync will reconcile
        $resource->delete();
    }

    public function requestAssignment(AccessPackage $package, string $email, ?string $justification = null): AccessPackageAssignment
    {
        if ($package->graph_id) {
            $this->graph->post('/identityGovernance/entitlementManagement/assignmentRequests', [
                'requestType' => 'AdminAdd',
                'accessPackageAssignment' => [
                    'targetId' => $email,
                    'assignmentPolicyId' => $package->graph_id,
                    'accessPackageId' => $package->graph_id,
                ],
                'justification' => $justification ?? '',
            ]);
        }

        return AccessPackageAssignment::create([
            'access_package_id' => $package->id,
            'target_user_email' => $email,
            'status' => AssignmentStatus::PendingApproval,
            'requested_at' => now(),
            'expires_at' => now()->addDays($package->duration_days),
            'justification' => $justification,
        ]);
    }

    public function approveAssignment(AccessPackageAssignment $assignment, User $approver): void
    {
        $assignment->update([
            'status' => AssignmentStatus::Approved,
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    public function denyAssignment(AccessPackageAssignment $assignment, User $denier): void
    {
        $assignment->update([
            'status' => AssignmentStatus::Denied,
        ]);
    }

    public function revokeAssignment(AccessPackageAssignment $assignment): void
    {
        if ($assignment->graph_id) {
            $this->graph->post('/identityGovernance/entitlementManagement/assignmentRequests', [
                'requestType' => 'AdminRemove',
                'accessPackageAssignment' => [
                    'id' => $assignment->graph_id,
                ],
            ]);
        }

        $assignment->update([
            'status' => AssignmentStatus::Revoked,
        ]);
    }

    public function listGroups(): array
    {
        $response = $this->graph->get('/groups', [
            '$select' => 'id,displayName,description',
            '$top' => 100,
            '$orderby' => 'displayName',
        ]);

        return $response['value'] ?? [];
    }

    public function listSharePointSites(): array
    {
        $response = $this->graph->get('/sites', [
            '$select' => 'id,displayName,webUrl',
            '$top' => 100,
            'search' => '*',
        ]);

        return $response['value'] ?? [];
    }

    public function syncAccessPackages(): int
    {
        $response = $this->graph->get('/identityGovernance/entitlementManagement/accessPackages', [
            '$expand' => 'catalog',
        ]);

        $synced = 0;
        foreach ($response['value'] ?? [] as $graphPackage) {
            $package = AccessPackage::where('graph_id', $graphPackage['id'])->first();
            if ($package) {
                $package->update([
                    'display_name' => $graphPackage['displayName'],
                    'is_active' => ! ($graphPackage['isHidden'] ?? false),
                    'last_synced_at' => now(),
                ]);
                $synced++;
            }
        }

        return $synced;
    }

    public function syncAssignments(): int
    {
        $response = $this->graph->get('/identityGovernance/entitlementManagement/assignments', [
            '$expand' => 'accessPackage',
        ]);

        $synced = 0;
        foreach ($response['value'] ?? [] as $graphAssignment) {
            $assignment = AccessPackageAssignment::where('graph_id', $graphAssignment['id'])->first();
            if ($assignment) {
                $graphStatus = strtolower($graphAssignment['state'] ?? '');
                $status = match ($graphStatus) {
                    'delivered' => AssignmentStatus::Delivered,
                    'expired' => AssignmentStatus::Expired,
                    'delivering' => AssignmentStatus::Delivering,
                    default => $assignment->status,
                };

                $assignment->update([
                    'status' => $status,
                    'last_synced_at' => now(),
                ]);
                $synced++;
            }
        }

        return $synced;
    }

    private function ensureConnectedOrganization(PartnerOrganization $partner): void
    {
        $response = $this->graph->get('/identityGovernance/entitlementManagement/connectedOrganizations', [
            '$filter' => "identitySources/any(is:is/tenantId eq '{$partner->tenant_id}')",
        ]);

        if (! empty($response['value'])) {
            return;
        }

        $this->graph->post('/identityGovernance/entitlementManagement/connectedOrganizations', [
            'displayName' => $partner->display_name,
            'identitySources' => [
                [
                    '@odata.type' => '#microsoft.graph.azureActiveDirectoryTenant',
                    'tenantId' => $partner->tenant_id,
                    'displayName' => $partner->display_name,
                ],
            ],
            'state' => 'configured',
        ]);
    }

    private function createAssignmentPolicy(AccessPackage $package, ?int $approverUserId): void
    {
        $policyData = [
            'displayName' => "Policy for {$package->display_name}",
            'accessPackageId' => $package->graph_id,
            'expiration' => [
                'type' => 'afterDuration',
                'duration' => "P{$package->duration_days}D",
            ],
            'requestorSettings' => [
                'scopeType' => 'AllExternalSubjects',
                'acceptRequests' => true,
            ],
        ];

        if ($approverUserId && $package->approval_required) {
            $approver = User::find($approverUserId);
            if ($approver) {
                $policyData['requestApprovalSettings'] = [
                    'isApprovalRequired' => true,
                    'isApprovalRequiredForExtension' => false,
                    'approvalStages' => [
                        [
                            'approvalStageTimeOutInDays' => 14,
                            'isApproverJustificationRequired' => false,
                            'isEscalationEnabled' => false,
                            'primaryApprovers' => [
                                [
                                    '@odata.type' => '#microsoft.graph.singleUser',
                                    'isBackup' => false,
                                    'description' => $approver->name,
                                ],
                            ],
                        ],
                    ],
                ];
            }
        }

        $this->graph->post('/identityGovernance/entitlementManagement/assignmentPolicies', $policyData);
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=EntitlementServiceTest`
Expected: All 10 tests pass.

**Step 5: Commit**

```bash
git add app/Services/EntitlementService.php tests/Feature/EntitlementServiceTest.php
git commit -m "feat(entitlements): add EntitlementService with Graph API integration and tests"
```

---

## Task 6: Form Requests

**Files:**
- Create: `app/Http/Requests/StoreAccessPackageRequest.php`
- Create: `app/Http/Requests/UpdateAccessPackageRequest.php`

**Step 1: Create StoreAccessPackageRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccessPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'partner_organization_id' => ['required', 'exists:partner_organizations,id'],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'approval_required' => ['required', 'boolean'],
            'approver_user_id' => ['nullable', 'exists:users,id'],
            'resources' => ['required', 'array', 'min:1'],
            'resources.*.resource_type' => ['required', 'in:group,sharepoint_site'],
            'resources.*.resource_id' => ['required', 'string'],
            'resources.*.resource_display_name' => ['required', 'string', 'max:255'],
        ];
    }
}
```

**Step 2: Create UpdateAccessPackageRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccessPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:365'],
            'approval_required' => ['sometimes', 'required', 'boolean'],
            'approver_user_id' => ['nullable', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

**Step 3: Commit**

```bash
git add app/Http/Requests/StoreAccessPackageRequest.php app/Http/Requests/UpdateAccessPackageRequest.php
git commit -m "feat(entitlements): add form request validation for access packages"
```

---

## Task 7: Controller & Routes

**Files:**
- Create: `app/Http/Controllers/EntitlementController.php`
- Modify: `routes/web.php`

**Step 1: Create EntitlementController**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\StoreAccessPackageRequest;
use App\Http\Requests\UpdateAccessPackageRequest;
use App\Models\AccessPackage;
use App\Models\AccessPackageAssignment;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EntitlementController extends Controller
{
    public function __construct(
        private EntitlementService $entitlementService,
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): Response
    {
        $packages = AccessPackage::with(['partnerOrganization', 'approver', 'createdBy'])
            ->withCount(['resources', 'assignments'])
            ->orderByDesc('created_at')
            ->paginate(25);

        return Inertia::render('entitlements/Index', [
            'packages' => $packages,
            'canManage' => $request->user()->role->canManage(),
            'isAdmin' => $request->user()->role->isAdmin(),
        ]);
    }

    public function create(Request $request): Response
    {
        if (! $request->user()->role->isAdmin()) {
            abort(403);
        }

        return Inertia::render('entitlements/Create', [
            'partners' => PartnerOrganization::orderBy('display_name')->get(['id', 'display_name', 'tenant_id']),
            'approvers' => User::whereIn('role', ['admin', 'operator'])->orderBy('name')->get(['id', 'name', 'role']),
        ]);
    }

    public function store(StoreAccessPackageRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $partner = PartnerOrganization::findOrFail($validated['partner_organization_id']);
        $catalog = $this->entitlementService->getOrCreateDefaultCatalog();

        $package = $this->entitlementService->createAccessPackage($catalog, $partner, [
            'display_name' => $validated['display_name'],
            'description' => $validated['description'] ?? null,
            'duration_days' => $validated['duration_days'],
            'approval_required' => $validated['approval_required'],
            'approver_user_id' => $validated['approver_user_id'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ]);

        foreach ($validated['resources'] as $resourceData) {
            $this->entitlementService->addResource($package, $resourceData);
        }

        $this->activityLog->log($request->user(), ActivityAction::AccessPackageCreated, $package, [
            'display_name' => $package->display_name,
            'partner' => $partner->display_name,
        ]);

        return redirect()->route('entitlements.index')->with('success', "Access package '{$package->display_name}' created.");
    }

    public function show(Request $request, AccessPackage $entitlement): Response
    {
        $entitlement->load([
            'partnerOrganization', 'catalog', 'approver', 'createdBy',
            'resources',
            'assignments' => fn ($q) => $q->orderByDesc('requested_at'),
            'assignments.approvedBy',
        ]);

        return Inertia::render('entitlements/Show', [
            'package' => $entitlement,
            'canManage' => $request->user()->role->canManage(),
            'isAdmin' => $request->user()->role->isAdmin(),
        ]);
    }

    public function update(UpdateAccessPackageRequest $request, AccessPackage $entitlement): RedirectResponse
    {
        $this->entitlementService->updateAccessPackage($entitlement, $request->validated());

        $this->activityLog->log($request->user(), ActivityAction::AccessPackageUpdated, $entitlement, [
            'display_name' => $entitlement->display_name,
        ]);

        return redirect()->back()->with('success', 'Access package updated.');
    }

    public function destroy(Request $request, AccessPackage $entitlement): RedirectResponse
    {
        if (! $request->user()->role->isAdmin()) {
            abort(403);
        }

        $name = $entitlement->display_name;
        $this->entitlementService->deleteAccessPackage($entitlement);

        $this->activityLog->log($request->user(), ActivityAction::AccessPackageDeleted, null, [
            'display_name' => $name,
        ]);

        return redirect()->route('entitlements.index')->with('success', 'Access package deleted.');
    }

    public function createAssignment(Request $request, AccessPackage $entitlement): RedirectResponse
    {
        if (! $request->user()->role->canManage()) {
            abort(403);
        }

        $validated = $request->validate([
            'target_user_email' => ['required', 'email', 'max:255'],
            'justification' => ['nullable', 'string', 'max:5000'],
        ]);

        $assignment = $this->entitlementService->requestAssignment(
            $entitlement,
            $validated['target_user_email'],
            $validated['justification'] ?? null,
        );

        $this->activityLog->log($request->user(), ActivityAction::AssignmentRequested, $entitlement, [
            'email' => $assignment->target_user_email,
        ]);

        return redirect()->back()->with('success', "Assignment requested for {$assignment->target_user_email}.");
    }

    public function approveAssignment(Request $request, AccessPackage $entitlement, AccessPackageAssignment $assignment): RedirectResponse
    {
        if (! $request->user()->role->canManage()) {
            abort(403);
        }

        $this->entitlementService->approveAssignment($assignment, $request->user());

        $this->activityLog->log($request->user(), ActivityAction::AssignmentApproved, $entitlement, [
            'email' => $assignment->target_user_email,
        ]);

        return redirect()->back()->with('success', 'Assignment approved.');
    }

    public function denyAssignment(Request $request, AccessPackage $entitlement, AccessPackageAssignment $assignment): RedirectResponse
    {
        if (! $request->user()->role->canManage()) {
            abort(403);
        }

        $this->entitlementService->denyAssignment($assignment, $request->user());

        $this->activityLog->log($request->user(), ActivityAction::AssignmentDenied, $entitlement, [
            'email' => $assignment->target_user_email,
        ]);

        return redirect()->back()->with('success', 'Assignment denied.');
    }

    public function revokeAssignment(Request $request, AccessPackage $entitlement, AccessPackageAssignment $assignment): RedirectResponse
    {
        if (! $request->user()->role->canManage()) {
            abort(403);
        }

        $this->entitlementService->revokeAssignment($assignment);

        $this->activityLog->log($request->user(), ActivityAction::AssignmentRevoked, $entitlement, [
            'email' => $assignment->target_user_email,
        ]);

        return redirect()->back()->with('success', 'Assignment revoked.');
    }

    public function groups(): JsonResponse
    {
        return response()->json($this->entitlementService->listGroups());
    }

    public function sharepointSites(): JsonResponse
    {
        return response()->json($this->entitlementService->listSharePointSites());
    }
}
```

**Step 2: Add routes to `routes/web.php`**

Add these lines inside the `Route::middleware(['auth', 'verified', 'approved'])` group, after the access-reviews routes:

```php
    Route::resource('entitlements', \App\Http\Controllers\EntitlementController::class)->except(['edit']);
    Route::post('entitlements/{entitlement}/assignments', [\App\Http\Controllers\EntitlementController::class, 'createAssignment'])->name('entitlements.assignments.create');
    Route::post('entitlements/{entitlement}/assignments/{assignment}/approve', [\App\Http\Controllers\EntitlementController::class, 'approveAssignment'])->name('entitlements.assignments.approve');
    Route::post('entitlements/{entitlement}/assignments/{assignment}/deny', [\App\Http\Controllers\EntitlementController::class, 'denyAssignment'])->name('entitlements.assignments.deny');
    Route::post('entitlements/{entitlement}/assignments/{assignment}/revoke', [\App\Http\Controllers\EntitlementController::class, 'revokeAssignment'])->name('entitlements.assignments.revoke');
    Route::get('entitlements-groups', [\App\Http\Controllers\EntitlementController::class, 'groups'])->name('entitlements.groups');
    Route::get('entitlements-sharepoint-sites', [\App\Http\Controllers\EntitlementController::class, 'sharepointSites'])->name('entitlements.sharepoint-sites');
```

**Step 3: Commit**

```bash
git add app/Http/Controllers/EntitlementController.php routes/web.php
git commit -m "feat(entitlements): add controller and routes for access package management"
```

---

## Task 8: TypeScript Types

**Files:**
- Create: `resources/js/types/entitlement.ts`

**Step 1: Create TypeScript types**

```typescript
// resources/js/types/entitlement.ts
export type AccessPackageCatalog = {
    id: number;
    graph_id: string | null;
    display_name: string;
    description: string | null;
    is_default: boolean;
    last_synced_at: string | null;
    created_at: string;
};

export type AccessPackage = {
    id: number;
    graph_id: string | null;
    catalog_id: number;
    catalog?: AccessPackageCatalog;
    partner_organization_id: number;
    partner_organization?: { id: number; display_name: string; tenant_id: string };
    display_name: string;
    description: string | null;
    duration_days: number;
    approval_required: boolean;
    approver_user_id: number | null;
    approver?: { id: number; name: string };
    is_active: boolean;
    created_by_user_id: number;
    created_by?: { id: number; name: string };
    resources_count?: number;
    assignments_count?: number;
    resources?: AccessPackageResource[];
    assignments?: AccessPackageAssignment[];
    last_synced_at: string | null;
    created_at: string;
};

export type AccessPackageResource = {
    id: number;
    access_package_id: number;
    resource_type: 'group' | 'sharepoint_site';
    resource_id: string;
    resource_display_name: string;
    graph_id: string | null;
};

export type AccessPackageAssignment = {
    id: number;
    graph_id: string | null;
    access_package_id: number;
    target_user_email: string;
    target_user_id: string | null;
    status: 'pending_approval' | 'approved' | 'denied' | 'delivering' | 'delivered' | 'expired' | 'revoked';
    approved_by_user_id: number | null;
    approved_by?: { id: number; name: string };
    expires_at: string | null;
    requested_at: string;
    approved_at: string | null;
    delivered_at: string | null;
    justification: string | null;
    last_synced_at: string | null;
};

export type GraphGroup = {
    id: string;
    displayName: string;
    description: string | null;
};

export type GraphSharePointSite = {
    id: string;
    displayName: string;
    webUrl: string;
};
```

**Step 2: Commit**

```bash
git add resources/js/types/entitlement.ts
git commit -m "feat(entitlements): add TypeScript types for access packages"
```

---

## Task 9: Vue Pages — Index

**Files:**
- Create: `resources/js/pages/entitlements/Index.vue`

**Step 1: Create Index page**

```vue
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import type { AccessPackage } from '@/types/entitlement';
import type { Paginated } from '@/types/partner';

defineProps<{
    packages: Paginated<AccessPackage>;
    canManage: boolean;
    isAdmin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Entitlements', href: '/entitlements' },
];

function formatDate(val: string | null): string {
    if (!val) return '\u2014';
    return new Date(val).toLocaleDateString();
}

function statusVariant(
    active: boolean,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    return active ? 'default' : 'secondary';
}
</script>

<template>
    <Head title="Entitlements" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Access Packages</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Self-service access packages for external partner users.
                    </p>
                </div>
                <Link v-if="isAdmin" href="/entitlements/create">
                    <Button>Create Package</Button>
                </Link>
            </div>

            <Card v-if="packages.data.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No access packages configured yet.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Partner</TableHead>
                        <TableHead>Resources</TableHead>
                        <TableHead>Assignments</TableHead>
                        <TableHead>Duration</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Created</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="pkg in packages.data" :key="pkg.id">
                        <TableCell>
                            <Link
                                :href="`/entitlements/${pkg.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ pkg.display_name }}
                            </Link>
                        </TableCell>
                        <TableCell>{{
                            pkg.partner_organization?.display_name ?? '\u2014'
                        }}</TableCell>
                        <TableCell>{{ pkg.resources_count ?? 0 }}</TableCell>
                        <TableCell>{{ pkg.assignments_count ?? 0 }}</TableCell>
                        <TableCell>{{ pkg.duration_days }}d</TableCell>
                        <TableCell>
                            <Badge :variant="statusVariant(pkg.is_active)">
                                {{ pkg.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ formatDate(pkg.created_at) }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/entitlements/Index.vue
git commit -m "feat(entitlements): add Index page for access packages"
```

---

## Task 10: Vue Pages — Create

**Files:**
- Create: `resources/js/pages/entitlements/Create.vue`

**Step 1: Create multi-step Create page**

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import type { GraphGroup, GraphSharePointSite } from '@/types/entitlement';

const props = defineProps<{
    partners: { id: number; display_name: string; tenant_id: string }[];
    approvers: { id: number; name: string; role: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Entitlements', href: '/entitlements' },
    { title: 'Create', href: '/entitlements/create' },
];

const step = ref(1);
const totalSteps = 4;

const availableGroups = ref<GraphGroup[]>([]);
const availableSites = ref<GraphSharePointSite[]>([]);
const loadingGroups = ref(false);
const loadingSites = ref(false);

const form = useForm({
    partner_organization_id: null as number | null,
    display_name: '',
    description: '',
    duration_days: 90,
    approval_required: true,
    approver_user_id: null as number | null,
    resources: [] as { resource_type: string; resource_id: string; resource_display_name: string }[],
});

const selectedPartner = computed(() =>
    props.partners.find((p) => p.id === form.partner_organization_id),
);

async function fetchGroups() {
    loadingGroups.value = true;
    try {
        const res = await fetch('/entitlements-groups');
        availableGroups.value = await res.json();
    } finally {
        loadingGroups.value = false;
    }
}

async function fetchSites() {
    loadingSites.value = true;
    try {
        const res = await fetch('/entitlements-sharepoint-sites');
        availableSites.value = await res.json();
    } finally {
        loadingSites.value = false;
    }
}

function goToStep(s: number) {
    if (s === 2 && !availableGroups.value.length && !loadingGroups.value) {
        fetchGroups();
        fetchSites();
    }
    step.value = s;
}

function addResource(type: string, id: string, name: string) {
    if (!form.resources.some((r) => r.resource_id === id)) {
        form.resources.push({
            resource_type: type,
            resource_id: id,
            resource_display_name: name,
        });
    }
}

function removeResource(index: number) {
    form.resources.splice(index, 1);
}

function submit() {
    form.post('/entitlements');
}
</script>

<template>
    <Head title="Create Access Package" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <h1 class="mb-2 text-2xl font-semibold">Create Access Package</h1>
            <p class="mb-6 text-sm text-muted-foreground">
                Step {{ step }} of {{ totalSteps }}
            </p>

            <!-- Step 1: Select Partner -->
            <form v-if="step === 1" @submit.prevent="goToStep(2)" class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Select Partner Organization</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <Label>Partner</Label>
                            <Select v-model="form.partner_organization_id">
                                <SelectTrigger><SelectValue placeholder="Select a partner" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="p in partners"
                                        :key="p.id"
                                        :value="p.id"
                                    >
                                        {{ p.display_name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="form.errors.partner_organization_id" class="mt-1 text-sm text-destructive">
                                {{ form.errors.partner_organization_id }}
                            </p>
                        </div>

                        <div>
                            <Label for="display_name">Package Name</Label>
                            <Input id="display_name" v-model="form.display_name" placeholder="e.g. Partner Dev Access" />
                            <p v-if="form.errors.display_name" class="mt-1 text-sm text-destructive">
                                {{ form.errors.display_name }}
                            </p>
                        </div>

                        <div>
                            <Label for="description">Description</Label>
                            <Textarea id="description" v-model="form.description" placeholder="Optional description..." />
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end">
                    <Button type="submit" :disabled="!form.partner_organization_id || !form.display_name">
                        Next: Add Resources
                    </Button>
                </div>
            </form>

            <!-- Step 2: Add Resources -->
            <div v-if="step === 2" class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Add Resources</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div v-if="form.resources.length" class="flex flex-col gap-2">
                            <p class="text-sm font-medium">Selected Resources:</p>
                            <div v-for="(r, i) in form.resources" :key="r.resource_id" class="flex items-center justify-between rounded border p-2">
                                <div class="flex items-center gap-2">
                                    <Badge variant="outline">{{ r.resource_type === 'group' ? 'Group' : 'SharePoint' }}</Badge>
                                    <span class="text-sm">{{ r.resource_display_name }}</span>
                                </div>
                                <Button variant="ghost" size="sm" @click="removeResource(i)">Remove</Button>
                            </div>
                        </div>
                        <p v-if="form.errors.resources" class="text-sm text-destructive">
                            {{ form.errors.resources }}
                        </p>

                        <div>
                            <p class="mb-2 text-sm font-medium">Groups</p>
                            <p v-if="loadingGroups" class="text-sm text-muted-foreground">Loading groups...</p>
                            <div v-else class="max-h-48 overflow-y-auto rounded border">
                                <div
                                    v-for="g in availableGroups"
                                    :key="g.id"
                                    class="flex cursor-pointer items-center justify-between border-b p-2 last:border-0 hover:bg-muted/50"
                                    @click="addResource('group', g.id, g.displayName)"
                                >
                                    <span class="text-sm">{{ g.displayName }}</span>
                                    <span v-if="form.resources.some(r => r.resource_id === g.id)" class="text-xs text-muted-foreground">Added</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <p class="mb-2 text-sm font-medium">SharePoint Sites</p>
                            <p v-if="loadingSites" class="text-sm text-muted-foreground">Loading sites...</p>
                            <div v-else class="max-h-48 overflow-y-auto rounded border">
                                <div
                                    v-for="s in availableSites"
                                    :key="s.id"
                                    class="flex cursor-pointer items-center justify-between border-b p-2 last:border-0 hover:bg-muted/50"
                                    @click="addResource('sharepoint_site', s.id, s.displayName)"
                                >
                                    <span class="text-sm">{{ s.displayName }}</span>
                                    <span v-if="form.resources.some(r => r.resource_id === s.id)" class="text-xs text-muted-foreground">Added</span>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-between">
                    <Button variant="outline" @click="goToStep(1)">Back</Button>
                    <Button :disabled="form.resources.length === 0" @click="goToStep(3)">Next: Configure Policy</Button>
                </div>
            </div>

            <!-- Step 3: Configure Policy -->
            <form v-if="step === 3" @submit.prevent="goToStep(4)" class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Access Policy</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <Label for="duration">Duration (days)</Label>
                            <Input id="duration" type="number" v-model.number="form.duration_days" min="1" max="365" />
                        </div>

                        <div class="flex items-center gap-3">
                            <Switch id="approval" v-model:checked="form.approval_required" />
                            <Label for="approval">Require approval</Label>
                        </div>

                        <div v-if="form.approval_required">
                            <Label>Approver</Label>
                            <Select v-model="form.approver_user_id">
                                <SelectTrigger><SelectValue placeholder="Select an approver" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="a in approvers" :key="a.id" :value="a.id">
                                        {{ a.name }} ({{ a.role }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-between">
                    <Button type="button" variant="outline" @click="goToStep(2)">Back</Button>
                    <Button type="submit">Next: Review</Button>
                </div>
            </form>

            <!-- Step 4: Review & Submit -->
            <div v-if="step === 4" class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Review Access Package</CardTitle>
                    </CardHeader>
                    <CardContent class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <span class="text-muted-foreground">Partner</span>
                        <span>{{ selectedPartner?.display_name }}</span>

                        <span class="text-muted-foreground">Package Name</span>
                        <span>{{ form.display_name }}</span>

                        <span v-if="form.description" class="text-muted-foreground">Description</span>
                        <span v-if="form.description">{{ form.description }}</span>

                        <span class="text-muted-foreground">Duration</span>
                        <span>{{ form.duration_days }} days</span>

                        <span class="text-muted-foreground">Approval Required</span>
                        <span>{{ form.approval_required ? 'Yes' : 'No' }}</span>

                        <span class="text-muted-foreground">Resources</span>
                        <div class="flex flex-col gap-1">
                            <div v-for="r in form.resources" :key="r.resource_id" class="flex items-center gap-1">
                                <Badge variant="outline" class="text-xs">{{ r.resource_type === 'group' ? 'Group' : 'SP' }}</Badge>
                                <span>{{ r.resource_display_name }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-between">
                    <Button variant="outline" @click="goToStep(3)">Back</Button>
                    <Button @click="submit" :disabled="form.processing">
                        {{ form.processing ? 'Creating...' : 'Create Package' }}
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/entitlements/Create.vue
git commit -m "feat(entitlements): add multi-step Create page for access packages"
```

---

## Task 11: Vue Pages — Show

**Files:**
- Create: `resources/js/pages/entitlements/Show.vue`

**Step 1: Create Show page with assignment management**

```vue
<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
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
import type { AccessPackage } from '@/types/entitlement';

const props = defineProps<{
    package: AccessPackage;
    canManage: boolean;
    isAdmin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Entitlements', href: '/entitlements' },
    { title: props.package.display_name, href: `/entitlements/${props.package.id}` },
];

function formatDate(val: string | null): string {
    if (!val) return '\u2014';
    return new Date(val).toLocaleDateString();
}

function statusVariant(
    status: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<string, 'default' | 'destructive' | 'outline' | 'secondary'> = {
        pending_approval: 'outline',
        approved: 'secondary',
        delivering: 'secondary',
        delivered: 'default',
        denied: 'destructive',
        expired: 'destructive',
        revoked: 'destructive',
    };
    return map[status] ?? 'outline';
}

function statusLabel(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const assignForm = useForm({
    target_user_email: '',
    justification: '',
});

function submitAssignment() {
    assignForm.post(`/entitlements/${props.package.id}/assignments`, {
        preserveScroll: true,
        onSuccess: () => assignForm.reset(),
    });
}

const showDeleteConfirm = ref(false);
const deleting = ref(false);

function deletePackage() {
    deleting.value = true;
    router.delete(`/entitlements/${props.package.id}`, {
        onFinish: () => { deleting.value = false; },
    });
}
</script>

<template>
    <Head :title="package.display_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">{{ package.display_name }}</h1>
                    <p v-if="package.description" class="mt-1 text-sm text-muted-foreground">
                        {{ package.description }}
                    </p>
                </div>
                <Badge :variant="package.is_active ? 'default' : 'secondary'">
                    {{ package.is_active ? 'Active' : 'Inactive' }}
                </Badge>
            </div>

            <Separator />

            <Card>
                <CardHeader>
                    <CardTitle>Configuration</CardTitle>
                </CardHeader>
                <CardContent class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <span class="text-muted-foreground">Partner</span>
                    <span>{{ package.partner_organization?.display_name ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Catalog</span>
                    <span>{{ package.catalog?.display_name ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Duration</span>
                    <span>{{ package.duration_days }} days</span>

                    <span class="text-muted-foreground">Approval Required</span>
                    <span>{{ package.approval_required ? 'Yes' : 'No' }}</span>

                    <span class="text-muted-foreground">Approver</span>
                    <span>{{ package.approver?.name ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Created By</span>
                    <span>{{ package.created_by?.name ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Created</span>
                    <span>{{ formatDate(package.created_at) }}</span>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Resources</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="package.resources?.length" class="flex flex-col gap-2">
                        <div v-for="r in package.resources" :key="r.id" class="flex items-center gap-2 rounded border p-2">
                            <Badge variant="outline">{{ r.resource_type === 'group' ? 'Group' : 'SharePoint' }}</Badge>
                            <span class="text-sm">{{ r.resource_display_name }}</span>
                        </div>
                    </div>
                    <p v-else class="py-4 text-center text-sm text-muted-foreground">No resources configured.</p>
                </CardContent>
            </Card>

            <Card v-if="canManage">
                <CardHeader>
                    <CardTitle>Request Assignment</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submitAssignment" class="flex flex-col gap-4">
                        <div>
                            <Label for="email">External User Email</Label>
                            <Input id="email" type="email" v-model="assignForm.target_user_email" placeholder="partner@external.com" />
                            <p v-if="assignForm.errors.target_user_email" class="mt-1 text-sm text-destructive">
                                {{ assignForm.errors.target_user_email }}
                            </p>
                        </div>
                        <div>
                            <Label for="justification">Justification</Label>
                            <Textarea id="justification" v-model="assignForm.justification" placeholder="Optional justification..." />
                        </div>
                        <div class="flex justify-end">
                            <Button type="submit" :disabled="assignForm.processing || !assignForm.target_user_email">
                                {{ assignForm.processing ? 'Requesting...' : 'Request Assignment' }}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Assignments</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table v-if="package.assignments?.length">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Email</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Requested</TableHead>
                                <TableHead>Expires</TableHead>
                                <TableHead>Approved By</TableHead>
                                <TableHead v-if="canManage"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="a in package.assignments" :key="a.id">
                                <TableCell class="font-medium">{{ a.target_user_email }}</TableCell>
                                <TableCell>
                                    <Badge :variant="statusVariant(a.status)">
                                        {{ statusLabel(a.status) }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ formatDate(a.requested_at) }}</TableCell>
                                <TableCell>{{ formatDate(a.expires_at) }}</TableCell>
                                <TableCell>{{ a.approved_by?.name ?? '\u2014' }}</TableCell>
                                <TableCell v-if="canManage">
                                    <div class="flex gap-1">
                                        <Button
                                            v-if="a.status === 'pending_approval'"
                                            variant="outline"
                                            size="sm"
                                            @click="router.post(`/entitlements/${package.id}/assignments/${a.id}/approve`, {}, { preserveScroll: true })"
                                        >
                                            Approve
                                        </Button>
                                        <Button
                                            v-if="a.status === 'pending_approval'"
                                            variant="outline"
                                            size="sm"
                                            @click="router.post(`/entitlements/${package.id}/assignments/${a.id}/deny`, {}, { preserveScroll: true })"
                                        >
                                            Deny
                                        </Button>
                                        <Button
                                            v-if="a.status === 'delivered'"
                                            variant="destructive"
                                            size="sm"
                                            @click="router.post(`/entitlements/${package.id}/assignments/${a.id}/revoke`, {}, { preserveScroll: true })"
                                        >
                                            Revoke
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p v-else class="py-6 text-center text-sm text-muted-foreground">
                        No assignments yet.
                    </p>
                </CardContent>
            </Card>

            <Card v-if="isAdmin" class="border-destructive/50">
                <CardHeader>
                    <CardTitle class="text-destructive">Danger Zone</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="!showDeleteConfirm">
                        <p class="mb-3 text-sm text-muted-foreground">
                            Delete this access package, its resources, and all assignments.
                        </p>
                        <Button variant="destructive" @click="showDeleteConfirm = true">
                            Delete Package
                        </Button>
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <p class="text-sm font-medium">Are you sure? This cannot be undone.</p>
                        <div class="flex gap-2">
                            <Button variant="destructive" @click="deletePackage" :disabled="deleting">
                                {{ deleting ? 'Deleting\u2026' : 'Yes, Delete' }}
                            </Button>
                            <Button variant="outline" @click="showDeleteConfirm = false">Cancel</Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/entitlements/Show.vue
git commit -m "feat(entitlements): add Show page with assignment management"
```

---

## Task 12: Navigation

**Files:**
- Modify: `resources/js/components/AppSidebar.vue`

**Step 1: Add Entitlements to sidebar navigation**

In `resources/js/components/AppSidebar.vue`:

1. Add `Package` to the lucide-vue-next import (line 1-11 area)
2. Add the nav item after "Access Reviews" entry in the `mainNavItems` computed array

Add to imports:
```typescript
import {
    Activity,
    Building2,
    ClipboardCheck,
    FileStack,
    LayoutGrid,
    Package,
    Settings,
    Users,
} from 'lucide-vue-next';
```

Add nav item after "Access Reviews" (after line 41):
```typescript
        {
            title: 'Entitlements',
            href: '/entitlements',
            icon: Package,
        },
```

**Step 2: Commit**

```bash
git add resources/js/components/AppSidebar.vue
git commit -m "feat(entitlements): add Entitlements to sidebar navigation"
```

---

## Task 13: Sync Command

**Files:**
- Create: `app/Console/Commands/SyncEntitlements.php`
- Modify: `routes/console.php`

**Step 1: Create SyncEntitlements command**

```php
<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use App\Services\EntitlementService;
use Illuminate\Console\Command;

class SyncEntitlements extends Command
{
    protected $signature = 'sync:entitlements';

    protected $description = 'Sync access packages and assignments from Microsoft Graph API';

    public function handle(EntitlementService $entitlementService): int
    {
        $log = SyncLog::create([
            'type' => 'entitlements',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Syncing access packages from Graph API...');
            $packagesSynced = $entitlementService->syncAccessPackages();
            $this->info("Synced {$packagesSynced} access packages.");

            $this->info('Syncing assignments from Graph API...');
            $assignmentsSynced = $entitlementService->syncAssignments();
            $this->info("Synced {$assignmentsSynced} assignments.");

            $log->update([
                'status' => 'completed',
                'records_synced' => $packagesSynced + $assignmentsSynced,
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

**Step 2: Add schedule entry to `routes/console.php`**

Add after the existing schedule entries (after `Schedule::command('score:partners')->daily();`):

```php
Schedule::command('sync:entitlements')->cron("*/{$partnersInterval} * * * *");
```

Also update the try block to include `$entitlementsInterval`:

```php
try {
    $partnersInterval = (int) Setting::get('sync', 'partners_interval_minutes', config('graph.sync_interval_minutes'));
    $guestsInterval = (int) Setting::get('sync', 'guests_interval_minutes', config('graph.sync_interval_minutes'));
    $entitlementsInterval = (int) Setting::get('sync', 'entitlements_interval_minutes', config('graph.sync_interval_minutes'));
} catch (\Throwable) {
    $partnersInterval = (int) config('graph.sync_interval_minutes', 15);
    $guestsInterval = (int) config('graph.sync_interval_minutes', 15);
    $entitlementsInterval = (int) config('graph.sync_interval_minutes', 15);
}
```

And add the schedule line:
```php
Schedule::command('sync:entitlements')->cron("*/{$entitlementsInterval} * * * *");
```

**Step 3: Commit**

```bash
git add app/Console/Commands/SyncEntitlements.php routes/console.php
git commit -m "feat(entitlements): add sync command and schedule for access packages"
```

---

## Task 14: Controller Feature Tests

**Files:**
- Create: `tests/Feature/EntitlementControllerTest.php`

**Step 1: Write controller feature tests**

```php
<?php

use App\Enums\AssignmentStatus;
use App\Models\AccessPackage;
use App\Models\AccessPackageAssignment;
use App\Models\AccessPackageCatalog;
use App\Models\AccessPackageResource;
use App\Models\PartnerOrganization;
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
});

test('index page lists access packages', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    AccessPackage::factory()->count(3)->create();

    $response = $this->actingAs($admin)->get('/entitlements');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('entitlements/Index')
        ->has('packages.data', 3)
    );
});

test('create page shows form for admin', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    PartnerOrganization::factory()->count(2)->create();

    $response = $this->actingAs($admin)->get('/entitlements/create');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('entitlements/Create')
        ->has('partners', 2)
        ->has('approvers')
    );
});

test('create page returns 403 for viewer', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);

    $response = $this->actingAs($viewer)->get('/entitlements/create');

    $response->assertForbidden();
});

test('store creates access package with resources', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response(['id' => 'graph-id', 'value' => []], 201),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'tenant-123']);
    $approver = User::factory()->create(['role' => 'operator']);
    AccessPackageCatalog::factory()->create(['graph_id' => 'catalog-123', 'is_default' => true]);

    $response = $this->actingAs($admin)->post('/entitlements', [
        'partner_organization_id' => $partner->id,
        'display_name' => 'Test Package',
        'description' => 'Test description',
        'duration_days' => 90,
        'approval_required' => true,
        'approver_user_id' => $approver->id,
        'resources' => [
            [
                'resource_type' => 'group',
                'resource_id' => 'group-123',
                'resource_display_name' => 'Dev Team',
            ],
        ],
    ]);

    $response->assertRedirect('/entitlements');
    expect(AccessPackage::count())->toBe(1);
    expect(AccessPackageResource::count())->toBe(1);
});

test('show page displays package details', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $package = AccessPackage::factory()->create();
    AccessPackageResource::factory()->count(2)->create(['access_package_id' => $package->id]);

    $response = $this->actingAs($admin)->get("/entitlements/{$package->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('entitlements/Show')
        ->has('package')
    );
});

test('destroy deletes package for admin', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response([], 204),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    $package = AccessPackage::factory()->create(['graph_id' => 'pkg-123']);

    $response = $this->actingAs($admin)->delete("/entitlements/{$package->id}");

    $response->assertRedirect('/entitlements');
    expect(AccessPackage::count())->toBe(0);
});

test('operator can create assignment', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response(['id' => 'request-123'], 201),
    ]);

    $operator = User::factory()->create(['role' => 'operator']);
    $package = AccessPackage::factory()->create(['graph_id' => 'pkg-123']);

    $response = $this->actingAs($operator)->post("/entitlements/{$package->id}/assignments", [
        'target_user_email' => 'user@external.com',
        'justification' => 'Need access',
    ]);

    $response->assertRedirect();
    expect(AccessPackageAssignment::count())->toBe(1);
});

test('operator can approve assignment', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response([], 200),
    ]);

    $operator = User::factory()->create(['role' => 'operator']);
    $package = AccessPackage::factory()->create();
    $assignment = AccessPackageAssignment::factory()->create([
        'access_package_id' => $package->id,
        'status' => AssignmentStatus::PendingApproval,
    ]);

    $response = $this->actingAs($operator)->post("/entitlements/{$package->id}/assignments/{$assignment->id}/approve");

    $response->assertRedirect();
    expect($assignment->fresh()->status)->toBe(AssignmentStatus::Approved);
});

test('viewer cannot create assignment', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $package = AccessPackage::factory()->create();

    $response = $this->actingAs($viewer)->post("/entitlements/{$package->id}/assignments", [
        'target_user_email' => 'user@external.com',
    ]);

    $response->assertForbidden();
});
```

**Step 2: Run all tests**

Run: `php artisan test --filter=EntitlementControllerTest`
Expected: All tests pass.

**Step 3: Commit**

```bash
git add tests/Feature/EntitlementControllerTest.php
git commit -m "test(entitlements): add controller feature tests for access package CRUD and assignments"
```

---

## Task 15: Lint, Format & Full Test Suite

**Step 1: Run linting and formatting**

Run: `composer run lint && npm run lint && npm run format`

Fix any issues found.

**Step 2: Run TypeScript type checking**

Run: `npm run types:check`

Fix any type errors.

**Step 3: Run full test suite**

Run: `composer run test`
Expected: All tests pass, no lint errors.

**Step 4: Commit any fixes**

```bash
git add -A
git commit -m "chore: fix lint and formatting issues for entitlements feature"
```

---

## Task 16: Final Integration Commit

**Step 1: Run full CI check**

Run: `composer run ci:check`
Expected: All checks pass.

**Step 2: Verify the feature end-to-end**

- Check that `/entitlements` route loads
- Check that sidebar shows "Entitlements" link
- Check that create form renders with multi-step wizard
- Check that `php artisan sync:entitlements` runs without errors (with mocked/test Graph API)

**Step 3: Create final feature commit if needed**

If any adjustments were needed in step 2, commit them:

```bash
git add -A
git commit -m "feat(entitlements): finalize entitlement management MVP"
```
