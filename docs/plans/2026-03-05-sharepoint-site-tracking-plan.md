# SharePoint Site Tracking Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a dedicated SharePoint Sites section and per-partner exposure view showing which sites external partners can access through their guest users.

**Architecture:** New `SharePointSite` and `SharePointSitePermission` models following the existing conditional access / sensitivity label pattern. A `SharePointSiteService` syncs sites and permissions from Graph API. Partner exposure is derived through `guest_users.partner_organization_id` -- no separate partner pivot. Read-only controller + Vue pages for the dedicated section, plus a card on the partner show page.

**Tech Stack:** Laravel 12, Pest PHP, Vue 3 + Inertia.js, shadcn-vue, TypeScript, Microsoft Graph API

---

### Task 1: Migration -- create sharepoint_sites and sharepoint_site_permissions tables

**Files:**
- Create: `database/migrations/2026_03_05_200000_create_sharepoint_sites_tables.php`

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
        Schema::create('sharepoint_sites', function (Blueprint $table) {
            $table->id();
            $table->string('site_id')->unique();
            $table->string('display_name');
            $table->string('url');
            $table->text('description')->nullable();
            $table->foreignId('sensitivity_label_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_sharing_capability')->default('Disabled');
            $table->unsignedBigInteger('storage_used_bytes')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->string('owner_display_name')->nullable();
            $table->string('owner_email')->nullable();
            $table->unsignedInteger('member_count')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sharepoint_site_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sharepoint_site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('granted_via');
            $table->timestamps();

            $table->unique(
                ['sharepoint_site_id', 'guest_user_id', 'role', 'granted_via'],
                'sp_site_guest_role_via_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sharepoint_site_permissions');
        Schema::dropIfExists('sharepoint_sites');
    }
};
```

**Step 2: Run migration to verify it works**

Run: `php artisan migrate`
Expected: Both tables created without errors.

**Step 3: Commit**

```bash
git add database/migrations/2026_03_05_200000_create_sharepoint_sites_tables.php
git commit -m "feat: add sharepoint_sites and sharepoint_site_permissions migrations"
```

---

### Task 2: Models -- SharePointSite and SharePointSitePermission

**Files:**
- Create: `app/Models/SharePointSite.php`
- Create: `app/Models/SharePointSitePermission.php`
- Modify: `app/Models/GuestUser.php` (add relationship)
- Modify: `app/Models/PartnerOrganization.php` (add relationship)
- Modify: `app/Enums/ActivityAction.php` (add enum case)

**Step 1: Create SharePointSite model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SharePointSite extends Model
{
    protected $fillable = [
        'site_id', 'display_name', 'url', 'description',
        'sensitivity_label_id', 'external_sharing_capability',
        'storage_used_bytes', 'last_activity_at',
        'owner_display_name', 'owner_email', 'member_count',
        'raw_json', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'storage_used_bytes' => 'integer',
            'member_count' => 'integer',
            'raw_json' => 'array',
            'last_activity_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function sensitivityLabel(): BelongsTo
    {
        return $this->belongsTo(SensitivityLabel::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(SharePointSitePermission::class);
    }

    public function guestUsers(): HasManyThrough
    {
        return $this->hasManyThrough(
            GuestUser::class,
            SharePointSitePermission::class,
            'sharepoint_site_id',
            'id',
            'id',
            'guest_user_id'
        );
    }
}
```

**Step 2: Create SharePointSitePermission model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharePointSitePermission extends Model
{
    protected $fillable = [
        'sharepoint_site_id', 'guest_user_id', 'role', 'granted_via',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(SharePointSite::class, 'sharepoint_site_id');
    }

    public function guestUser(): BelongsTo
    {
        return $this->belongsTo(GuestUser::class);
    }
}
```

**Step 3: Add relationship to GuestUser model**

Add at the bottom of `app/Models/GuestUser.php`, after the `invitedBy()` method (around line 38):

```php
public function sharePointSitePermissions(): HasMany
{
    return $this->hasMany(SharePointSitePermission::class);
}
```

Also add the import at top: `use Illuminate\Database\Eloquent\Relations\HasMany;`

**Step 4: Add relationship to PartnerOrganization model**

Add at the bottom of `app/Models/PartnerOrganization.php`, after the `sensitivityLabels()` method (around line 81):

```php
public function sharePointSites(): \Illuminate\Database\Eloquent\Collection
{
    $guestUserIds = $this->guestUsers()->pluck('id');

    return SharePointSite::whereHas('permissions', function ($q) use ($guestUserIds) {
        $q->whereIn('guest_user_id', $guestUserIds);
    })->get();
}
```

Note: This is a convenience method, not a relationship, because the mapping goes through two intermediate tables.

**Step 5: Add ActivityAction enum case**

Add to `app/Enums/ActivityAction.php` (after `SensitivityLabelsSynced` on line 35):

```php
case SharePointSitesSynced = 'sharepoint_sites_synced';
```

**Step 6: Commit**

```bash
git add app/Models/SharePointSite.php app/Models/SharePointSitePermission.php app/Models/GuestUser.php app/Models/PartnerOrganization.php app/Enums/ActivityAction.php
git commit -m "feat: add SharePointSite and SharePointSitePermission models"
```

---

### Task 3: SharePointSiteService -- sync sites

**Files:**
- Create: `app/Services/SharePointSiteService.php`
- Test: `tests/Feature/Services/SharePointSiteServiceTest.php`

**Step 1: Write the failing test for syncSites**

```php
<?php

use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\SharePointSite;
use App\Models\SharePointSitePermission;
use App\Services\SharePointSiteService;
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

function fakeGraphForSharePoint(array $sites = [], array $permissionsBySiteId = [], array $labelsBySiteId = []): void
{
    $responses = [
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/sites?*' => Http::response([
            'value' => $sites,
        ]),
    ];

    foreach ($sites as $site) {
        $siteId = $site['id'];
        $permissions = $permissionsBySiteId[$siteId] ?? [];
        $responses["graph.microsoft.com/v1.0/sites/{$siteId}/permissions*"] = Http::response([
            'value' => $permissions,
        ]);

        $labelId = $labelsBySiteId[$siteId] ?? null;
        $responses["graph.microsoft.com/v1.0/sites/{$siteId}/sensitivityLabel*"] = Http::response(
            $labelId ? ['sensitivityLabelId' => $labelId] : []
        );
    }

    Http::fake($responses);
}

function makeGraphSite(array $overrides = []): array
{
    return array_merge([
        'id' => 'site-1',
        'displayName' => 'Project Alpha',
        'name' => 'ProjectAlpha',
        'webUrl' => 'https://contoso.sharepoint.com/sites/alpha',
        'description' => 'Collaboration site',
        'sharingCapability' => 'ExternalUserAndGuestSharing',
    ], $overrides);
}

test('syncSites upserts sites from Graph API', function () {
    fakeGraphForSharePoint([makeGraphSite()]);

    $service = app(SharePointSiteService::class);
    $result = $service->syncSites();

    expect($result)->toBe(1);
    expect(SharePointSite::count())->toBe(1);

    $site = SharePointSite::first();
    expect($site->site_id)->toBe('site-1');
    expect($site->display_name)->toBe('Project Alpha');
    expect($site->url)->toBe('https://contoso.sharepoint.com/sites/alpha');
    expect($site->external_sharing_capability)->toBe('ExternalUserAndGuestSharing');
});

test('syncSites removes stale sites on re-sync', function () {
    SharePointSite::create([
        'site_id' => 'stale-site',
        'display_name' => 'Old Site',
        'url' => 'https://contoso.sharepoint.com/sites/old',
        'synced_at' => now()->subDay(),
    ]);

    fakeGraphForSharePoint([makeGraphSite()]);

    $service = app(SharePointSiteService::class);
    $service->syncSites();

    expect(SharePointSite::count())->toBe(1);
    expect(SharePointSite::first()->site_id)->toBe('site-1');
});

test('syncSites links sensitivity label when present', function () {
    $label = SensitivityLabel::create([
        'label_id' => 'label-1',
        'name' => 'Confidential',
        'protection_type' => 'encryption',
        'synced_at' => now(),
    ]);

    fakeGraphForSharePoint(
        [makeGraphSite()],
        [],
        ['site-1' => 'label-1']
    );

    $service = app(SharePointSiteService::class);
    $service->syncSites();

    $site = SharePointSite::first();
    expect($site->sensitivity_label_id)->toBe($label->id);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SharePointSiteServiceTest`
Expected: FAIL -- `SharePointSiteService` class not found.

**Step 3: Write the syncSites implementation**

```php
<?php

namespace App\Services;

use App\Exceptions\GraphApiException;
use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\SharePointSite;
use App\Models\SharePointSitePermission;
use App\Models\GuestUser;
use Illuminate\Support\Facades\Log;

class SharePointSiteService
{
    public function __construct(
        private MicrosoftGraphService $graph,
    ) {}

    public function syncSites(): int
    {
        $graphSites = $this->fetchSitesFromGraph();
        $syncedIds = [];

        foreach ($graphSites as $graphSite) {
            $labelId = $this->fetchSiteLabelFromGraph($graphSite['id']);
            $sensitivityLabel = $labelId ? SensitivityLabel::where('label_id', $labelId)->first() : null;

            $site = SharePointSite::updateOrCreate(
                ['site_id' => $graphSite['id']],
                [
                    'display_name' => $graphSite['displayName'] ?? $graphSite['name'] ?? 'Unknown',
                    'url' => $graphSite['webUrl'] ?? '',
                    'description' => $graphSite['description'] ?? null,
                    'sensitivity_label_id' => $sensitivityLabel?->id,
                    'external_sharing_capability' => $graphSite['sharingCapability'] ?? 'Disabled',
                    'storage_used_bytes' => $graphSite['storageUsed'] ?? null,
                    'last_activity_at' => $graphSite['lastModifiedDateTime'] ?? null,
                    'owner_display_name' => $graphSite['createdBy']['user']['displayName'] ?? null,
                    'owner_email' => $graphSite['createdBy']['user']['email'] ?? null,
                    'member_count' => null,
                    'raw_json' => $graphSite,
                    'synced_at' => now(),
                ]
            );

            $syncedIds[] = $site->id;
        }

        SharePointSite::whereNotIn('id', $syncedIds)->delete();

        return count($syncedIds);
    }

    public function syncPermissions(): int
    {
        $sites = SharePointSite::where('external_sharing_capability', '!=', 'Disabled')->get();
        $syncedIds = [];

        foreach ($sites as $site) {
            $graphPermissions = $this->fetchPermissionsFromGraph($site->site_id);

            foreach ($graphPermissions as $graphPerm) {
                $grantedTo = $graphPerm['grantedToV2'] ?? $graphPerm['grantedTo'] ?? null;
                if (! $grantedTo) {
                    continue;
                }

                $email = $grantedTo['spiUser']['email']
                    ?? $grantedTo['user']['email']
                    ?? $grantedTo['spiUser']['loginName']
                    ?? null;

                if (! $email) {
                    continue;
                }

                $guestUser = GuestUser::where('email', $email)
                    ->orWhere('user_principal_name', $email)
                    ->first();

                if (! $guestUser) {
                    continue;
                }

                $roles = $graphPerm['roles'] ?? ['read'];
                $grantedVia = $this->determineGrantedVia($graphPerm);

                foreach ($roles as $role) {
                    $record = SharePointSitePermission::updateOrCreate(
                        [
                            'sharepoint_site_id' => $site->id,
                            'guest_user_id' => $guestUser->id,
                            'role' => $role,
                            'granted_via' => $grantedVia,
                        ]
                    );
                    $syncedIds[] = $record->id;
                }
            }
        }

        SharePointSitePermission::whereNotIn('id', $syncedIds)->delete();

        return count($syncedIds);
    }

    public function getPartnerExposure(PartnerOrganization $partner): \Illuminate\Database\Eloquent\Collection
    {
        $guestUserIds = $partner->guestUsers()->pluck('id');

        return SharePointSite::whereHas('permissions', function ($q) use ($guestUserIds) {
            $q->whereIn('guest_user_id', $guestUserIds);
        })->with(['sensitivityLabel', 'permissions' => function ($q) use ($guestUserIds) {
            $q->whereIn('guest_user_id', $guestUserIds)->with('guestUser');
        }])->get();
    }

    private function fetchSitesFromGraph(): array
    {
        $response = $this->graph->get('/sites', [
            'search' => '*',
            '$select' => 'id,displayName,name,webUrl,description,sharingCapability,storageUsed,lastModifiedDateTime,createdBy',
            '$top' => 999,
        ]);

        return $this->requireValueKey($response, 'sites');
    }

    private function fetchPermissionsFromGraph(string $siteId): array
    {
        try {
            $response = $this->graph->get("/sites/{$siteId}/permissions");

            return $response['value'] ?? [];
        } catch (GraphApiException $e) {
            Log::warning("Failed to fetch permissions for site {$siteId}: {$e->getMessage()}");

            return [];
        }
    }

    private function fetchSiteLabelFromGraph(string $siteId): ?string
    {
        try {
            $response = $this->graph->get("/sites/{$siteId}/sensitivityLabel");

            return $response['sensitivityLabelId'] ?? null;
        } catch (GraphApiException $e) {
            Log::warning("Failed to fetch sensitivity label for site {$siteId}: {$e->getMessage()}");

            return null;
        }
    }

    private function determineGrantedVia(array $graphPerm): string
    {
        if (! empty($graphPerm['link'])) {
            return 'sharing_link';
        }

        if (! empty($graphPerm['inheritedFrom'])) {
            return 'group_membership';
        }

        return 'direct';
    }

    private function requireValueKey(array $response, string $context): array
    {
        if (! array_key_exists('value', $response)) {
            Log::error("Graph API response missing \"value\" key for {$context}", [
                'response_keys' => array_keys($response),
            ]);
            throw new \RuntimeException("Unexpected Graph API response structure for {$context}");
        }

        return $response['value'];
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=SharePointSiteServiceTest`
Expected: All 3 tests PASS.

**Step 5: Commit**

```bash
git add app/Services/SharePointSiteService.php tests/Feature/Services/SharePointSiteServiceTest.php
git commit -m "feat: add SharePointSiteService with syncSites and tests"
```

---

### Task 4: SharePointSiteService -- sync permissions tests

**Files:**
- Modify: `tests/Feature/Services/SharePointSiteServiceTest.php`

**Step 1: Add permission sync tests**

Append to the existing test file:

```php
test('syncPermissions matches guest users by email', function () {
    $partner = PartnerOrganization::factory()->create();
    $guest = GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'email' => 'guest@partner.com',
    ]);

    $site = SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    fakeGraphForSharePoint(
        [makeGraphSite()],
        [
            'site-1' => [
                [
                    'id' => 'perm-1',
                    'roles' => ['write'],
                    'grantedToV2' => [
                        'user' => ['email' => 'guest@partner.com'],
                    ],
                ],
            ],
        ]
    );

    $service = app(SharePointSiteService::class);
    $result = $service->syncPermissions();

    expect($result)->toBe(1);
    expect(SharePointSitePermission::count())->toBe(1);

    $perm = SharePointSitePermission::first();
    expect($perm->sharepoint_site_id)->toBe($site->id);
    expect($perm->guest_user_id)->toBe($guest->id);
    expect($perm->role)->toBe('write');
    expect($perm->granted_via)->toBe('direct');
});

test('syncPermissions skips non-guest users', function () {
    SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    fakeGraphForSharePoint(
        [makeGraphSite()],
        [
            'site-1' => [
                [
                    'id' => 'perm-1',
                    'roles' => ['read'],
                    'grantedToV2' => [
                        'user' => ['email' => 'unknown@external.com'],
                    ],
                ],
            ],
        ]
    );

    $service = app(SharePointSiteService::class);
    $result = $service->syncPermissions();

    expect($result)->toBe(0);
    expect(SharePointSitePermission::count())->toBe(0);
});

test('syncPermissions removes stale permissions', function () {
    $partner = PartnerOrganization::factory()->create();
    $guest = GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'email' => 'guest@partner.com',
    ]);

    $site = SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    SharePointSitePermission::create([
        'sharepoint_site_id' => $site->id,
        'guest_user_id' => $guest->id,
        'role' => 'owner',
        'granted_via' => 'direct',
    ]);

    fakeGraphForSharePoint(
        [makeGraphSite()],
        ['site-1' => []]
    );

    $service = app(SharePointSiteService::class);
    $service->syncPermissions();

    expect(SharePointSitePermission::count())->toBe(0);
});

test('syncPermissions detects sharing_link granted_via', function () {
    $partner = PartnerOrganization::factory()->create();
    $guest = GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'email' => 'guest@partner.com',
    ]);

    SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    fakeGraphForSharePoint(
        [makeGraphSite()],
        [
            'site-1' => [
                [
                    'id' => 'perm-1',
                    'roles' => ['read'],
                    'grantedToV2' => [
                        'user' => ['email' => 'guest@partner.com'],
                    ],
                    'link' => ['type' => 'view', 'webUrl' => 'https://...'],
                ],
            ],
        ]
    );

    $service = app(SharePointSiteService::class);
    $service->syncPermissions();

    expect(SharePointSitePermission::first()->granted_via)->toBe('sharing_link');
});

test('getPartnerExposure returns sites accessible by partner guests', function () {
    $partner = PartnerOrganization::factory()->create();
    $otherPartner = PartnerOrganization::factory()->create();

    $guest = GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'email' => 'guest@partner.com',
    ]);

    $site = SharePointSite::create([
        'site_id' => 'site-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    $otherSite = SharePointSite::create([
        'site_id' => 'site-2',
        'display_name' => 'Internal Only',
        'url' => 'https://contoso.sharepoint.com/sites/internal',
        'external_sharing_capability' => 'Disabled',
        'synced_at' => now(),
    ]);

    SharePointSitePermission::create([
        'sharepoint_site_id' => $site->id,
        'guest_user_id' => $guest->id,
        'role' => 'read',
        'granted_via' => 'direct',
    ]);

    $service = app(SharePointSiteService::class);
    $result = $service->getPartnerExposure($partner);

    expect($result)->toHaveCount(1);
    expect($result->first()->site_id)->toBe('site-1');

    $otherResult = $service->getPartnerExposure($otherPartner);
    expect($otherResult)->toHaveCount(0);
});
```

**Step 2: Run tests to verify they pass**

Run: `php artisan test --filter=SharePointSiteServiceTest`
Expected: All 8 tests PASS.

**Step 3: Commit**

```bash
git add tests/Feature/Services/SharePointSiteServiceTest.php
git commit -m "test: add permission sync and partner exposure tests for SharePointSiteService"
```

---

### Task 5: SyncSharePointSites Artisan command

**Files:**
- Create: `app/Console/Commands/SyncSharePointSites.php`
- Modify: `routes/console.php` (add to schedule)
- Test: `tests/Feature/Commands/SyncSharePointSitesTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant-id',
        'graph.client_id' => 'test-client-id',
        'graph.client_secret' => 'test-client-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
    ]);
});

test('sync:sharepoint-sites command creates sync log and activity log on success', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response(['value' => []]),
    ]);

    $this->artisan('sync:sharepoint-sites')->assertSuccessful();

    $syncLog = SyncLog::where('type', 'sharepoint_sites')->first();
    expect($syncLog)->not->toBeNull();
    expect($syncLog->status)->toBe('completed');

    $activityLog = ActivityLog::where('action', ActivityAction::SharePointSitesSynced)->first();
    expect($activityLog)->not->toBeNull();
    expect($activityLog->user_id)->toBeNull();
});

test('sync:sharepoint-sites command logs failure on error', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/sites*' => Http::response([], 500),
    ]);

    $this->artisan('sync:sharepoint-sites')->assertFailed();

    $syncLog = SyncLog::where('type', 'sharepoint_sites')->first();
    expect($syncLog)->not->toBeNull();
    expect($syncLog->status)->toBe('failed');
    expect($syncLog->error_message)->not->toBeNull();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SyncSharePointSitesTest`
Expected: FAIL -- command not found.

**Step 3: Create the Artisan command**

```php
<?php

namespace App\Console\Commands;

use App\Enums\ActivityAction;
use App\Models\SyncLog;
use App\Services\ActivityLogService;
use App\Services\SharePointSiteService;
use Illuminate\Console\Command;

class SyncSharePointSites extends Command
{
    protected $signature = 'sync:sharepoint-sites';

    protected $description = 'Sync SharePoint sites and guest user permissions from Microsoft Graph API';

    public function handle(SharePointSiteService $service): int
    {
        $log = SyncLog::create([
            'type' => 'sharepoint_sites',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Fetching SharePoint sites from Graph API...');
            $sitesSynced = $service->syncSites();
            $this->info("Synced {$sitesSynced} SharePoint sites.");

            $this->info('Syncing site permissions...');
            $permissionsSynced = $service->syncPermissions();
            $this->info("Synced {$permissionsSynced} site permissions.");

            $log->update([
                'status' => 'completed',
                'records_synced' => $sitesSynced + $permissionsSynced,
                'completed_at' => now(),
            ]);

            app(ActivityLogService::class)->logSystem(ActivityAction::SharePointSitesSynced, details: [
                'sites_synced' => $sitesSynced,
                'permissions_synced' => $permissionsSynced,
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

**Step 4: Add to schedule in `routes/console.php`**

Add after the `sync:sensitivity-labels` schedule line (line 27):

```php
Schedule::command('sync:sharepoint-sites')->cron("*/{$partnersInterval} * * * *");
```

**Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=SyncSharePointSitesTest`
Expected: All 2 tests PASS.

**Step 6: Commit**

```bash
git add app/Console/Commands/SyncSharePointSites.php routes/console.php tests/Feature/Commands/SyncSharePointSitesTest.php
git commit -m "feat: add sync:sharepoint-sites command with schedule"
```

---

### Task 6: SharePointSiteController

**Files:**
- Create: `app/Http/Controllers/SharePointSiteController.php`
- Modify: `routes/web.php` (add routes)
- Test: `tests/Feature/Controllers/SharePointSiteControllerTest.php`

**Step 1: Write the failing tests**

```php
<?php

use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\SharePointSite;
use App\Models\SharePointSitePermission;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->actingAs($this->user);
});

test('index page renders with sites', function () {
    SharePointSite::create([
        'site_id' => 's-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    $response = $this->get('/sharepoint-sites');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('sharepoint-sites/Index')
        ->has('sites.data', 1)
        ->where('sites.data.0.display_name', 'Project Alpha')
        ->has('uncoveredPartnerCount')
    );
});

test('index shows uncovered partner count', function () {
    PartnerOrganization::factory()->count(3)->create();

    $site = SharePointSite::create([
        'site_id' => 's-1',
        'display_name' => 'Test',
        'url' => 'https://contoso.sharepoint.com/sites/test',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    $partner = PartnerOrganization::first();
    $guest = GuestUser::factory()->create(['partner_organization_id' => $partner->id]);
    SharePointSitePermission::create([
        'sharepoint_site_id' => $site->id,
        'guest_user_id' => $guest->id,
        'role' => 'read',
        'granted_via' => 'direct',
    ]);

    $response = $this->get('/sharepoint-sites');

    $response->assertInertia(fn ($page) => $page
        ->where('uncoveredPartnerCount', 2)
    );
});

test('show page renders with site and permissions', function () {
    $partner = PartnerOrganization::factory()->create();
    $guest = GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'email' => 'guest@partner.com',
    ]);

    $site = SharePointSite::create([
        'site_id' => 's-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    SharePointSitePermission::create([
        'sharepoint_site_id' => $site->id,
        'guest_user_id' => $guest->id,
        'role' => 'write',
        'granted_via' => 'direct',
    ]);

    $response = $this->get("/sharepoint-sites/{$site->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('sharepoint-sites/Show')
        ->where('site.display_name', 'Project Alpha')
        ->has('site.permissions', 1)
    );
});

test('partner show page includes sharepoint sites', function () {
    $partner = PartnerOrganization::factory()->create();
    $guest = GuestUser::factory()->create(['partner_organization_id' => $partner->id]);

    $site = SharePointSite::create([
        'site_id' => 's-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    SharePointSitePermission::create([
        'sharepoint_site_id' => $site->id,
        'guest_user_id' => $guest->id,
        'role' => 'read',
        'granted_via' => 'direct',
    ]);

    $response = $this->get("/partners/{$partner->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('sharePointSites', 1)
    );
});

test('viewer role can access sharepoint sites index', function () {
    $viewer = User::factory()->create(['role' => 'viewer', 'approved_at' => now()]);
    $this->actingAs($viewer);

    $response = $this->get('/sharepoint-sites');

    $response->assertOk();
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SharePointSiteControllerTest`
Expected: FAIL -- 404 (routes not registered).

**Step 3: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\PartnerOrganization;
use App\Models\SharePointSite;
use App\Models\SharePointSitePermission;
use Inertia\Inertia;
use Inertia\Response;

class SharePointSiteController extends Controller
{
    public function index(): Response
    {
        $sites = SharePointSite::with('sensitivityLabel')
            ->withCount('permissions')
            ->orderBy('display_name')
            ->paginate(25);

        $partnerIdsWithAccess = PartnerOrganization::whereHas('guestUsers', function ($q) {
            $q->whereHas('sharePointSitePermissions');
        })->pluck('id');

        $uncoveredPartnerCount = PartnerOrganization::whereNotIn('id', $partnerIdsWithAccess)->count();

        return Inertia::render('sharepoint-sites/Index', [
            'sites' => $sites,
            'uncoveredPartnerCount' => $uncoveredPartnerCount,
        ]);
    }

    public function show(SharePointSite $sharePointSite): Response
    {
        $sharePointSite->load([
            'sensitivityLabel',
            'permissions.guestUser.partnerOrganization',
        ]);

        return Inertia::render('sharepoint-sites/Show', [
            'site' => $sharePointSite,
        ]);
    }
}
```

**Step 4: Add routes to `routes/web.php`**

Add the `use` import at the top (around line 10, after the existing controller imports):

```php
use App\Http\Controllers\SharePointSiteController;
```

Add the routes inside the auth middleware group, after the sensitivity-labels routes (after line 54):

```php
Route::get('sharepoint-sites', [SharePointSiteController::class, 'index'])->name('sharepoint-sites.index');
Route::get('sharepoint-sites/{sharePointSite}', [SharePointSiteController::class, 'show'])->name('sharepoint-sites.show');
```

**Step 5: Modify PartnerOrganizationController::show() to include SharePoint sites**

In `app/Http/Controllers/PartnerOrganizationController.php`, add the import at the top:

```php
use App\Services\SharePointSiteService;
```

In the `show()` method (around line 119), add after the `$sensitivityLabels` query (line 135) and before the `return`:

```php
$sharePointSites = app(SharePointSiteService::class)->getPartnerExposure($partner);
```

Then add `'sharePointSites' => $sharePointSites,` to the Inertia render array (after line 143).

**Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=SharePointSiteControllerTest`
Expected: All 5 tests PASS.

**Step 7: Commit**

```bash
git add app/Http/Controllers/SharePointSiteController.php app/Http/Controllers/PartnerOrganizationController.php routes/web.php tests/Feature/Controllers/SharePointSiteControllerTest.php
git commit -m "feat: add SharePointSiteController with routes and partner show integration"
```

---

### Task 7: TypeScript types and nav sidebar

**Files:**
- Create: `resources/js/types/sharepoint.ts`
- Modify: `resources/js/components/AppSidebar.vue`

**Step 1: Create TypeScript type**

```typescript
import type { SensitivityLabel } from './sensitivity-label';
import type { GuestUser, PartnerOrganization } from './partner';

export type SharePointSite = {
    id: number;
    site_id: string;
    display_name: string;
    url: string;
    description: string | null;
    external_sharing_capability: string;
    sensitivity_label?: SensitivityLabel | null;
    owner_display_name: string | null;
    owner_email: string | null;
    storage_used_bytes: number | null;
    last_activity_at: string | null;
    member_count: number | null;
    synced_at: string | null;
    permissions_count?: number;
    permissions?: SharePointSitePermission[];
};

export type SharePointSitePermission = {
    id: number;
    sharepoint_site_id: number;
    guest_user_id: number;
    role: string;
    granted_via: 'direct' | 'sharing_link' | 'group_membership';
    guest_user?: GuestUser & {
        partner_organization?: PartnerOrganization;
    };
};
```

**Step 2: Add to nav sidebar**

In `resources/js/components/AppSidebar.vue`, add the `HardDrive` import to the lucide-vue-next imports (line 3-16):

```typescript
import {
    Activity,
    BarChart3,
    BookOpen,
    Building2,
    ClipboardCheck,
    FileStack,
    HardDrive,
    LayoutGrid,
    Package,
    Settings,
    Shield,
    Tag,
    Users,
} from 'lucide-vue-next';
```

Add the nav item after the Sensitivity Labels entry (after line 56):

```typescript
{
    title: 'SharePoint Sites',
    href: '/sharepoint-sites',
    icon: HardDrive,
},
```

**Step 3: Run type checking**

Run: `npm run types:check`
Expected: No new type errors.

**Step 4: Commit**

```bash
git add resources/js/types/sharepoint.ts resources/js/components/AppSidebar.vue
git commit -m "feat: add SharePoint types and sidebar navigation"
```

---

### Task 8: SharePoint Sites Index page (Vue)

**Files:**
- Create: `resources/js/pages/sharepoint-sites/Index.vue`

**Step 1: Create the Index page**

Follow the exact pattern from `resources/js/pages/conditional-access/Index.vue`:

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
import type { SharePointSite } from '@/types/sharepoint';
import type { Paginated } from '@/types/partner';

defineProps<{
    sites: Paginated<SharePointSite>;
    uncoveredPartnerCount: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'SharePoint Sites', href: '/sharepoint-sites' },
];

function sharingVariant(
    capability: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<
        string,
        'default' | 'destructive' | 'outline' | 'secondary'
    > = {
        Disabled: 'outline',
        ExistingExternalUserSharingOnly: 'secondary',
        ExternalUserSharingOnly: 'secondary',
        ExternalUserAndGuestSharing: 'default',
    };
    return map[capability] ?? 'outline';
}

function sharingLabel(capability: string): string {
    const map: Record<string, string> = {
        Disabled: 'Disabled',
        ExistingExternalUserSharingOnly: 'Existing external users',
        ExternalUserSharingOnly: 'External users only',
        ExternalUserAndGuestSharing: 'External users & guests',
    };
    return map[capability] ?? capability;
}
</script>

<template>
    <Head title="SharePoint Sites" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div>
                <h1 class="text-2xl font-semibold">SharePoint Sites</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    SharePoint sites with external sharing and guest user access.
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
                    no guest users with SharePoint site access.
                </p>
            </div>

            <Card v-if="sites.data.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No SharePoint sites found. Run the sync command or wait
                    for the next scheduled sync.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Site Name</TableHead>
                        <TableHead>URL</TableHead>
                        <TableHead>Sharing</TableHead>
                        <TableHead>Sensitivity Label</TableHead>
                        <TableHead>Guest Permissions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="site in sites.data" :key="site.id">
                        <TableCell>
                            <Link
                                :href="`/sharepoint-sites/${site.id}`"
                                class="font-medium hover:underline"
                            >
                                {{ site.display_name }}
                            </Link>
                        </TableCell>
                        <TableCell class="max-w-xs truncate text-xs text-muted-foreground">
                            {{ site.url }}
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    sharingVariant(
                                        site.external_sharing_capability,
                                    )
                                "
                            >
                                {{
                                    sharingLabel(
                                        site.external_sharing_capability,
                                    )
                                }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <template v-if="site.sensitivity_label">
                                <span
                                    v-if="site.sensitivity_label.color"
                                    class="mr-1.5 inline-block size-2.5 rounded-full"
                                    :style="{
                                        backgroundColor:
                                            site.sensitivity_label.color,
                                    }"
                                />
                                {{ site.sensitivity_label.name }}
                            </template>
                            <span v-else class="text-muted-foreground"
                                >&mdash;</span
                            >
                        </TableCell>
                        <TableCell>{{
                            site.permissions_count ?? 0
                        }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
```

**Step 2: Run lint and type check**

Run: `npm run lint && npm run types:check`
Expected: No errors.

**Step 3: Commit**

```bash
git add resources/js/pages/sharepoint-sites/Index.vue
git commit -m "feat: add SharePoint Sites index page"
```

---

### Task 9: SharePoint Sites Show page (Vue)

**Files:**
- Create: `resources/js/pages/sharepoint-sites/Show.vue`

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
import type { SharePointSite } from '@/types/sharepoint';

const props = defineProps<{
    site: SharePointSite;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'SharePoint Sites', href: '/sharepoint-sites' },
    {
        title: props.site.display_name,
        href: `/sharepoint-sites/${props.site.id}`,
    },
];

function sharingVariant(
    capability: string,
): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<
        string,
        'default' | 'destructive' | 'outline' | 'secondary'
    > = {
        Disabled: 'outline',
        ExistingExternalUserSharingOnly: 'secondary',
        ExternalUserSharingOnly: 'secondary',
        ExternalUserAndGuestSharing: 'default',
    };
    return map[capability] ?? 'outline';
}

function sharingLabel(capability: string): string {
    const map: Record<string, string> = {
        Disabled: 'Disabled',
        ExistingExternalUserSharingOnly: 'Existing external users',
        ExternalUserSharingOnly: 'External users only',
        ExternalUserAndGuestSharing: 'External users & guests',
    };
    return map[capability] ?? capability;
}

function formatBytes(bytes: number | null): string {
    if (bytes === null || bytes === undefined) return '\u2014';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    let val = bytes;
    while (val >= 1024 && i < units.length - 1) {
        val /= 1024;
        i++;
    }
    return `${val.toFixed(1)} ${units[i]}`;
}

function formatDate(val: string | null): string {
    if (!val) return '\u2014';
    return new Date(val).toLocaleString();
}

function grantedViaLabel(via: string): string {
    const map: Record<string, string> = {
        direct: 'Direct',
        sharing_link: 'Sharing Link',
        group_membership: 'Group Membership',
    };
    return map[via] ?? via;
}
</script>

<template>
    <Head :title="site.display_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold">
                            {{ site.display_name }}
                        </h1>
                        <Badge
                            :variant="
                                sharingVariant(
                                    site.external_sharing_capability,
                                )
                            "
                        >
                            {{
                                sharingLabel(site.external_sharing_capability)
                            }}
                        </Badge>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ site.url }}
                    </p>
                </div>
                <a
                    :href="site.url"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <Button variant="outline" size="sm">
                        <ExternalLink class="mr-2 size-4" />
                        Open in SharePoint
                    </Button>
                </a>
            </div>

            <Separator />

            <Card>
                <CardHeader>
                    <CardTitle>Site Details</CardTitle>
                </CardHeader>
                <CardContent
                    class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm"
                >
                    <span class="text-muted-foreground">Description</span>
                    <span>{{ site.description || '\u2014' }}</span>

                    <span class="text-muted-foreground"
                        >Sensitivity Label</span
                    >
                    <span>
                        <template v-if="site.sensitivity_label">
                            <span
                                v-if="site.sensitivity_label.color"
                                class="mr-1.5 inline-block size-2.5 rounded-full"
                                :style="{
                                    backgroundColor:
                                        site.sensitivity_label.color,
                                }"
                            />
                            {{ site.sensitivity_label.name }}
                        </template>
                        <template v-else>&mdash;</template>
                    </span>

                    <span class="text-muted-foreground">Owner</span>
                    <span>{{
                        site.owner_display_name || '\u2014'
                    }}</span>

                    <span class="text-muted-foreground">Owner Email</span>
                    <span>{{ site.owner_email || '\u2014' }}</span>

                    <span class="text-muted-foreground">Storage Used</span>
                    <span>{{ formatBytes(site.storage_used_bytes) }}</span>

                    <span class="text-muted-foreground">Last Activity</span>
                    <span>{{ formatDate(site.last_activity_at) }}</span>

                    <span class="text-muted-foreground">Last Synced</span>
                    <span>{{ formatDate(site.synced_at) }}</span>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle
                        >Guest Access ({{
                            site.permissions?.length ?? 0
                        }})</CardTitle
                    >
                </CardHeader>
                <CardContent>
                    <Table v-if="site.permissions?.length">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Guest User</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Partner</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Granted Via</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="perm in site.permissions"
                                :key="perm.id"
                            >
                                <TableCell>
                                    <Link
                                        v-if="perm.guest_user"
                                        :href="`/guests/${perm.guest_user.id}`"
                                        class="font-medium hover:underline"
                                    >
                                        {{
                                            perm.guest_user.display_name ??
                                            perm.guest_user.email
                                        }}
                                    </Link>
                                    <span v-else class="text-muted-foreground"
                                        >&mdash;</span
                                    >
                                </TableCell>
                                <TableCell>{{
                                    perm.guest_user?.email ?? '\u2014'
                                }}</TableCell>
                                <TableCell>
                                    <Link
                                        v-if="
                                            perm.guest_user
                                                ?.partner_organization
                                        "
                                        :href="`/partners/${perm.guest_user.partner_organization.id}`"
                                        class="hover:underline"
                                    >
                                        {{
                                            perm.guest_user
                                                .partner_organization
                                                .display_name
                                        }}
                                    </Link>
                                    <span v-else class="text-muted-foreground"
                                        >&mdash;</span
                                    >
                                </TableCell>
                                <TableCell>
                                    <Badge variant="secondary">
                                        {{ perm.role }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline">
                                        {{ grantedViaLabel(perm.granted_via) }}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p
                        v-else
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No guest users have access to this site.
                    </p>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
```

**Step 2: Run lint and type check**

Run: `npm run lint && npm run types:check`
Expected: No errors.

**Step 3: Commit**

```bash
git add resources/js/pages/sharepoint-sites/Show.vue
git commit -m "feat: add SharePoint Sites show page"
```

---

### Task 10: Partner show page -- SharePoint Sites card

**Files:**
- Modify: `resources/js/pages/partners/Show.vue`

**Step 1: Add the SharePoint Sites card**

In `resources/js/pages/partners/Show.vue`:

Add import for `HardDrive` to the lucide-vue-next imports (line 3):
```typescript
import { AlertTriangle, CircleHelp, HardDrive, Shield, Tag } from 'lucide-vue-next';
```

Add import for the SharePoint type (after line 48):
```typescript
import type { SharePointSite, SharePointSitePermission } from '@/types/sharepoint';
```

Add `sharePointSites` to the props definition (after `sensitivityLabels`, around line 63):
```typescript
sharePointSites: (SharePointSite & {
    permissions: SharePointSitePermission[];
})[];
```

Add the card template after the Sensitivity Labels card (after line 833, before the Guest Users card). Place it between the `<!-- Sensitivity Labels -->` Card closing tag and the `<!-- Guest Users -->` Card:

```vue
<!-- SharePoint Sites -->
<Card>
    <CardHeader>
        <CardTitle class="flex items-center gap-2">
            <HardDrive class="size-5" />
            SharePoint Sites ({{ sharePointSites.length }})
        </CardTitle>
    </CardHeader>
    <CardContent>
        <div
            v-if="sharePointSites.length === 0"
            class="flex items-center gap-3 rounded-lg border border-yellow-300 bg-yellow-50 p-4 dark:border-yellow-700 dark:bg-yellow-950"
        >
            <AlertTriangle
                class="size-5 shrink-0 text-yellow-600 dark:text-yellow-400"
            />
            <p class="text-sm text-yellow-800 dark:text-yellow-200">
                No SharePoint sites are accessible by this partner's
                guests.
                <Link href="/sharepoint-sites" class="underline"
                    >View all sites</Link
                >
            </p>
        </div>
        <Table v-else>
            <TableHeader>
                <TableRow>
                    <TableHead>Site</TableHead>
                    <TableHead>Sharing</TableHead>
                    <TableHead>Sensitivity Label</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="site in sharePointSites"
                    :key="site.id"
                >
                    <TableCell>
                        <Link
                            :href="`/sharepoint-sites/${site.id}`"
                            class="font-medium hover:underline"
                        >
                            {{ site.display_name }}
                        </Link>
                    </TableCell>
                    <TableCell>
                        <Badge variant="secondary">
                            {{
                                {
                                    Disabled: 'Disabled',
                                    ExistingExternalUserSharingOnly:
                                        'Existing external',
                                    ExternalUserSharingOnly:
                                        'External only',
                                    ExternalUserAndGuestSharing:
                                        'External & guests',
                                }[
                                    site.external_sharing_capability
                                ] ?? site.external_sharing_capability
                            }}
                        </Badge>
                    </TableCell>
                    <TableCell>
                        <template v-if="site.sensitivity_label">
                            <span
                                v-if="site.sensitivity_label.color"
                                class="mr-1.5 inline-block size-2.5 rounded-full"
                                :style="{
                                    backgroundColor:
                                        site.sensitivity_label.color,
                                }"
                            />
                            {{ site.sensitivity_label.name }}
                        </template>
                        <span
                            v-else
                            class="text-muted-foreground"
                            >&mdash;</span
                        >
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </CardContent>
</Card>
```

**Step 2: Run lint and type check**

Run: `npm run lint && npm run types:check`
Expected: No errors.

**Step 3: Commit**

```bash
git add resources/js/pages/partners/Show.vue
git commit -m "feat: add SharePoint Sites card to partner show page"
```

---

### Task 11: Full test suite verification

**Step 1: Run full CI check**

Run: `composer run ci:check`
Expected: All linting, formatting, type checking, and tests pass.

**Step 2: If any failures, fix them**

Address any lint/format/type/test failures found.

**Step 3: Commit any fixes**

```bash
git add -A
git commit -m "fix: address CI check issues for SharePoint site tracking"
```

---

### Task 12: Dev seeder data (optional)

**Files:**
- Modify: `database/seeders/DevSeeder.php`

**Step 1: Add sample SharePoint site data to DevSeeder**

Add after the existing sensitivity label seeding section. Create 3-4 sample SharePoint sites with varying sharing capabilities and a few permission records linking to existing seeded guest users.

**Step 2: Run seeder to verify**

Run: `php artisan db:seed --class=DevSeeder`
Expected: No errors, sample data created.

**Step 3: Commit**

```bash
git add database/seeders/DevSeeder.php
git commit -m "chore: add SharePoint site sample data to DevSeeder"
```
