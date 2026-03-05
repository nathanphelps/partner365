# Admin Panel Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add an admin panel at `/admin` with Microsoft Graph configuration, user management (with approval workflow), and sync settings with history.

**Architecture:** Database-backed settings with encrypted secret storage and `.env` fallback. New `EnsureApproved` middleware gates unapproved users. Admin routes protected by existing `role:admin` middleware. Sync commands write to a `sync_logs` table and read intervals from settings.

**Tech Stack:** Laravel 12, Pest PHP, Vue 3 + Inertia.js, shadcn-vue, Tailwind CSS, TypeScript

**Design doc:** `docs/plans/2026-03-04-admin-panel-design.md`

**Note:** This project uses Wayfinder for auto-generated TypeScript route helpers (`resources/js/routes/`). After adding Laravel routes, run `php artisan wayfinder:generate` to regenerate them. Do NOT manually create route helper files.

---

### Task 1: Setting Model and Migration

**Files:**
- Create: `database/migrations/2026_03_04_200000_create_settings_table.php`
- Create: `app/Models/Setting.php`
- Create: `tests/Feature/Models/SettingTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Models/SettingTest.php
<?php

use App\Models\Setting;

test('Setting::set stores a value', function () {
    Setting::set('graph', 'tenant_id', 'abc-123');

    $this->assertDatabaseHas('settings', [
        'group' => 'graph',
        'key' => 'tenant_id',
        'encrypted' => false,
    ]);
});

test('Setting::get retrieves a stored value', function () {
    Setting::set('graph', 'tenant_id', 'abc-123');

    expect(Setting::get('graph', 'tenant_id'))->toBe('abc-123');
});

test('Setting::get returns fallback when no value exists', function () {
    expect(Setting::get('graph', 'tenant_id', 'fallback-value'))->toBe('fallback-value');
});

test('Setting::set upserts on duplicate group+key', function () {
    Setting::set('graph', 'tenant_id', 'first');
    Setting::set('graph', 'tenant_id', 'second');

    expect(Setting::where('group', 'graph')->where('key', 'tenant_id')->count())->toBe(1);
    expect(Setting::get('graph', 'tenant_id'))->toBe('second');
});

test('Setting::set encrypts value when encrypted flag is true', function () {
    Setting::set('graph', 'client_secret', 'super-secret', encrypted: true);

    $raw = Setting::where('group', 'graph')->where('key', 'client_secret')->first();
    expect($raw->encrypted)->toBeTrue();
    // Raw DB value should not be the plaintext
    expect($raw->getRawOriginal('value'))->not->toBe('super-secret');
});

test('Setting::get decrypts encrypted values', function () {
    Setting::set('graph', 'client_secret', 'super-secret', encrypted: true);

    expect(Setting::get('graph', 'client_secret'))->toBe('super-secret');
});

test('Setting::getGroup returns all values for a group', function () {
    Setting::set('graph', 'tenant_id', 'tid');
    Setting::set('graph', 'client_id', 'cid');
    Setting::set('sync', 'interval', '15');

    $group = Setting::getGroup('graph');
    expect($group)->toHaveKey('tenant_id', 'tid');
    expect($group)->toHaveKey('client_id', 'cid');
    expect($group)->not->toHaveKey('interval');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SettingTest`
Expected: FAIL — table and model don't exist

**Step 3: Create the migration**

```php
// database/migrations/2026_03_04_200000_create_settings_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('group');
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
```

**Step 4: Create the Setting model**

```php
// app/Models/Setting.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    use HasUlids;

    protected $fillable = ['group', 'key', 'value', 'encrypted'];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    public static function get(string $group, string $key, mixed $fallback = null): mixed
    {
        $setting = static::where('group', $group)->where('key', $key)->first();

        if (!$setting || $setting->value === null) {
            return $fallback;
        }

        return $setting->encrypted ? Crypt::decryptString($setting->value) : $setting->value;
    }

    public static function set(string $group, string $key, ?string $value, bool $encrypted = false): void
    {
        static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            [
                'value' => $encrypted && $value !== null ? Crypt::encryptString($value) : $value,
                'encrypted' => $encrypted,
            ]
        );
    }

    public static function getGroup(string $group): array
    {
        return static::where('group', $group)
            ->get()
            ->mapWithKeys(fn (self $s) => [
                $s->key => $s->encrypted ? Crypt::decryptString($s->value) : $s->value,
            ])
            ->toArray();
    }
}
```

**Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SettingTest`
Expected: All 7 tests PASS

**Step 6: Commit**

```bash
git add database/migrations/2026_03_04_200000_create_settings_table.php app/Models/Setting.php tests/Feature/Models/SettingTest.php
git commit -m "feat: add Setting model with encrypted storage and env fallback"
```

---

### Task 2: SyncLog Model and Migration

**Files:**
- Create: `database/migrations/2026_03_04_200001_create_sync_logs_table.php`
- Create: `app/Models/SyncLog.php`
- Create: `tests/Feature/Models/SyncLogTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Models/SyncLogTest.php
<?php

use App\Models\SyncLog;

test('SyncLog can be created with required fields', function () {
    $log = SyncLog::create([
        'type' => 'partners',
        'status' => 'running',
        'started_at' => now(),
    ]);

    expect($log->type)->toBe('partners');
    expect($log->status)->toBe('running');
});

test('scopeRecent returns limited results in descending order', function () {
    SyncLog::factory()->count(15)->create(['type' => 'partners']);

    $recent = SyncLog::recent(10)->get();
    expect($recent)->toHaveCount(10);
    expect($recent->first()->started_at->gte($recent->last()->started_at))->toBeTrue();
});

test('scopeByType filters by sync type', function () {
    SyncLog::factory()->count(3)->create(['type' => 'partners']);
    SyncLog::factory()->count(2)->create(['type' => 'guests']);

    expect(SyncLog::byType('partners')->count())->toBe(3);
    expect(SyncLog::byType('guests')->count())->toBe(2);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SyncLogTest`
Expected: FAIL — table, model, factory don't exist

**Step 3: Create migration**

```php
// database/migrations/2026_03_04_200001_create_sync_logs_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type');
            $table->string('status');
            $table->unsignedInteger('records_synced')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
```

**Step 4: Create model and factory**

```php
// app/Models/SyncLog.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class SyncLog extends Model
{
    use HasUlids, HasFactory;

    public $timestamps = false;

    protected $fillable = ['type', 'status', 'records_synced', 'error_message', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function scopeRecent(Builder $query, int $limit = 10): Builder
    {
        return $query->orderByDesc('started_at')->limit($limit);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
```

```php
// database/factories/SyncLogFactory.php
<?php

namespace Database\Factories;

use App\Models\SyncLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class SyncLogFactory extends Factory
{
    protected $model = SyncLog::class;

    public function definition(): array
    {
        $started = $this->faker->dateTimeBetween('-1 week');
        return [
            'type' => $this->faker->randomElement(['partners', 'guests']),
            'status' => 'completed',
            'records_synced' => $this->faker->numberBetween(0, 50),
            'started_at' => $started,
            'completed_at' => (clone $started)->modify('+' . $this->faker->numberBetween(1, 30) . ' seconds'),
        ];
    }
}
```

**Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SyncLogTest`
Expected: All 3 tests PASS

**Step 6: Commit**

```bash
git add database/migrations/2026_03_04_200001_create_sync_logs_table.php app/Models/SyncLog.php database/factories/SyncLogFactory.php tests/Feature/Models/SyncLogTest.php
git commit -m "feat: add SyncLog model with scopes for recent and by-type"
```

---

### Task 3: User Approval — Migration and Model Changes

**Files:**
- Create: `database/migrations/2026_03_04_200002_add_approval_to_users_table.php`
- Modify: `app/Models/User.php`
- Create: `tests/Feature/Models/UserApprovalTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Models/UserApprovalTest.php
<?php

use App\Models\User;

test('new user is not approved by default', function () {
    $user = User::factory()->create();

    expect($user->isApproved())->toBeFalse();
    expect($user->approved_at)->toBeNull();
});

test('user can be approved', function () {
    $admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $user = User::factory()->create();

    $user->approve($admin);

    expect($user->isApproved())->toBeTrue();
    expect($user->approved_at)->not->toBeNull();
    expect($user->approved_by)->toBe($admin->id);
});

test('scopePending returns only unapproved users', function () {
    User::factory()->create(['approved_at' => now()]);
    User::factory()->create(['approved_at' => null]);
    User::factory()->create(['approved_at' => null]);

    expect(User::pending()->count())->toBe(2);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserApprovalTest`
Expected: FAIL — `isApproved()` method and columns don't exist

**Step 3: Create migration**

```php
// database/migrations/2026_03_04_200002_add_approval_to_users_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('role');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });

        // Backfill: approve all existing users
        DB::table('users')->whereNull('approved_at')->update(['approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_at', 'approved_by']);
        });
    }
};
```

**Step 4: Add methods to User model**

Modify `app/Models/User.php`:

Add to `$fillable`:
```php
protected $fillable = [
    'name',
    'email',
    'password',
    'role',
    'approved_at',
    'approved_by',
];
```

Add to `casts()`:
```php
'approved_at' => 'datetime',
```

Add methods after `casts()`:
```php
public function isApproved(): bool
{
    return $this->approved_at !== null;
}

public function approve(User $approver): void
{
    $this->update([
        'approved_at' => now(),
        'approved_by' => $approver->id,
    ]);
}

public function scopePending($query)
{
    return $query->whereNull('approved_at');
}
```

**Step 5: Run test to verify it passes**

Run: `php artisan test --filter=UserApprovalTest`
Expected: All 3 tests PASS

**Step 6: Run full test suite to check for regressions**

Run: `php artisan test`
Expected: All existing tests still pass. Note: tests that create users via factory will now have `approved_at = null` by default. If any existing tests fail because of this, update the User factory to set `approved_at => now()` by default, since the migration backfills existing users.

**Step 7: Commit**

```bash
git add database/migrations/2026_03_04_200002_add_approval_to_users_table.php app/Models/User.php tests/Feature/Models/UserApprovalTest.php
git commit -m "feat: add user approval workflow with approved_at and approved_by"
```

---

### Task 4: EnsureApproved Middleware

**Files:**
- Create: `app/Http/Middleware/EnsureApproved.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Middleware/EnsureApprovedTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Middleware/EnsureApprovedTest.php
<?php

use App\Models\User;

test('approved user can access protected routes', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

test('unapproved user is shown pending approval page', function () {
    $user = User::factory()->create(['approved_at' => null]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->component('PendingApproval'));
});

test('unapproved user can still access auth routes', function () {
    $user = User::factory()->create(['approved_at' => null]);

    // Settings profile should still work (auth middleware only, no approved check)
    $this->actingAs($user)
        ->get('/settings/profile')
        ->assertOk();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EnsureApprovedTest`
Expected: FAIL — middleware doesn't exist

**Step 3: Create middleware**

```php
// app/Http/Middleware/EnsureApproved.php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && !$request->user()->isApproved()) {
            return Inertia::render('PendingApproval')->toResponse($request);
        }

        return $next($request);
    }
}
```

**Step 4: Register middleware and apply to routes**

Modify `bootstrap/app.php` — add alias:
```php
$middleware->alias([
    'role' => \App\Http\Middleware\CheckRole::class,
    'approved' => \App\Http\Middleware\EnsureApproved::class,
]);
```

Modify `routes/web.php` — add `approved` middleware to the protected group:
```php
Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    // ... all existing protected routes
});
```

Note: The `settings` routes in `routes/settings.php` use only `auth` middleware (no `approved`), so unapproved users can still access their profile. This is intentional.

**Step 5: Create PendingApproval Vue page (minimal)**

```vue
<!-- resources/js/pages/PendingApproval.vue -->
<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Pending Approval" />
    <div class="flex min-h-screen items-center justify-center bg-background p-4">
        <Card class="w-full max-w-md">
            <CardHeader class="text-center">
                <div class="mx-auto mb-4">
                    <AppLogoIcon class="h-10 w-10" />
                </div>
                <CardTitle>Account Pending Approval</CardTitle>
                <CardDescription>
                    Your account is awaiting administrator approval.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4 text-center">
                <p class="text-sm text-muted-foreground">
                    Signed in as <span class="font-medium text-foreground">{{ user.email }}</span>
                </p>
                <Link href="/logout" method="post" as="button" class="w-full">
                    <Button variant="outline" class="w-full">Log out</Button>
                </Link>
            </CardContent>
        </Card>
    </div>
</template>
```

**Step 6: Run test to verify it passes**

Run: `php artisan test --filter=EnsureApprovedTest`
Expected: All 3 tests PASS

**Step 7: Run full test suite**

Run: `php artisan test`
Expected: All tests pass. If existing tests fail because users are now unapproved by default, update the User factory `definition()` to include `'approved_at' => now()`.

**Step 8: Commit**

```bash
git add app/Http/Middleware/EnsureApproved.php bootstrap/app.php routes/web.php resources/js/pages/PendingApproval.vue tests/Feature/Middleware/EnsureApprovedTest.php
git commit -m "feat: add EnsureApproved middleware and PendingApproval page"
```

---

### Task 5: Extend ActivityAction Enum

**Files:**
- Modify: `app/Enums/ActivityAction.php`

**Step 1: Add new enum cases**

Add these cases to `app/Enums/ActivityAction.php`:

```php
case SettingsUpdated = 'settings_updated';
case UserApproved = 'user_approved';
case UserRoleChanged = 'user_role_changed';
case UserDeleted = 'user_deleted';
case SyncTriggered = 'sync_triggered';
```

**Step 2: Run full test suite**

Run: `php artisan test`
Expected: All tests pass (enum additions are backwards-compatible)

**Step 3: Commit**

```bash
git add app/Enums/ActivityAction.php
git commit -m "feat: add admin activity action types to ActivityAction enum"
```

---

### Task 6: Refactor MicrosoftGraphService to Use Setting Model

**Files:**
- Modify: `app/Services/MicrosoftGraphService.php`
- Modify: `tests/Feature/Services/MicrosoftGraphServiceTest.php`

**Step 1: Write the new test**

Add to `tests/Feature/Services/MicrosoftGraphServiceTest.php`:

```php
test('it reads credentials from Setting model with config fallback', function () {
    // Set DB overrides for tenant_id and client_id only
    \App\Models\Setting::set('graph', 'tenant_id', 'db-tenant-id');
    \App\Models\Setting::set('graph', 'client_id', 'db-client-id');
    // client_secret NOT set in DB — should fall back to config

    Http::fake([
        'login.microsoftonline.com/db-tenant-id/*' => Http::response([
            'access_token' => 'db-token',
            'expires_in' => 3600,
        ]),
    ]);

    $service = app(MicrosoftGraphService::class);
    $token = $service->getAccessToken();

    expect($token)->toBe('db-token');

    // Verify it used DB tenant_id in the URL
    Http::assertSent(fn ($request) => str_contains($request->url(), 'db-tenant-id'));
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MicrosoftGraphServiceTest`
Expected: FAIL — service still reads from config only

**Step 3: Update MicrosoftGraphService**

Replace `app/Services/MicrosoftGraphService.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\GraphApiException;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MicrosoftGraphService
{
    public function getAccessToken(): string
    {
        return Cache::remember('msgraph_access_token', 3500, function () {
            $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));

            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => Setting::get('graph', 'client_id', config('graph.client_id')),
                    'client_secret' => Setting::get('graph', 'client_secret', config('graph.client_secret')),
                    'scope' => Setting::get('graph', 'scopes', config('graph.scopes')),
                ]
            );

            return $response->json('access_token');
        });
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, query: $query);
    }

    public function post(string $path, array $data = []): array
    {
        return $this->request('POST', $path, data: $data);
    }

    public function patch(string $path, array $data = []): array
    {
        return $this->request('PATCH', $path, data: $data);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    private function request(string $method, string $path, array $data = [], array $query = []): array
    {
        $token = $this->getAccessToken();
        $baseUrl = Setting::get('graph', 'base_url', config('graph.base_url'));
        $url = $baseUrl . $path;

        $request = Http::withToken($token)->acceptJson();

        $response = match ($method) {
            'GET' => $request->get($url, $query),
            'POST' => $request->post($url, $data),
            'PATCH' => $request->patch($url, $data),
            'DELETE' => $request->delete($url),
        };

        if ($response->failed()) {
            throw GraphApiException::fromResponse($response->status(), $response->json() ?? []);
        }

        return $response->json() ?? [];
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=MicrosoftGraphServiceTest`
Expected: All tests PASS (existing tests still work because Setting falls back to config)

**Step 5: Commit**

```bash
git add app/Services/MicrosoftGraphService.php tests/Feature/Services/MicrosoftGraphServiceTest.php
git commit -m "feat: refactor MicrosoftGraphService to read from Setting with config fallback"
```

---

### Task 7: Admin Routes and Graph Controller

**Files:**
- Create: `routes/admin.php`
- Modify: `bootstrap/app.php` (add admin routes)
- Create: `app/Http/Controllers/Admin/AdminGraphController.php`
- Create: `app/Http/Requests/UpdateGraphSettingsRequest.php`
- Create: `tests/Feature/Admin/AdminGraphControllerTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Admin/AdminGraphControllerTest.php
<?php

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'approved_at' => now(),
    ]);
});

test('admin can view graph settings page', function () {
    $this->actingAs($this->admin)
        ->get('/admin/graph')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Graph'));
});

test('non-admin cannot access admin graph page', function () {
    $viewer = User::factory()->create([
        'role' => UserRole::Viewer,
        'approved_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->get('/admin/graph')
        ->assertForbidden();
});

test('operator cannot access admin graph page', function () {
    $operator = User::factory()->create([
        'role' => UserRole::Operator,
        'approved_at' => now(),
    ]);

    $this->actingAs($operator)
        ->get('/admin/graph')
        ->assertForbidden();
});

test('admin can update graph settings', function () {
    $this->actingAs($this->admin)
        ->put('/admin/graph', [
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'client_id' => '660e8400-e29b-41d4-a716-446655440000',
            'client_secret' => 'new-secret',
            'scopes' => 'https://graph.microsoft.com/.default',
            'base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_interval_minutes' => '30',
        ])
        ->assertRedirect('/admin/graph');

    expect(Setting::get('graph', 'tenant_id'))->toBe('550e8400-e29b-41d4-a716-446655440000');
    expect(Setting::get('graph', 'client_secret'))->toBe('new-secret');
});

test('update with blank client_secret preserves existing secret', function () {
    Setting::set('graph', 'client_secret', 'existing-secret', encrypted: true);

    $this->actingAs($this->admin)
        ->put('/admin/graph', [
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'client_id' => '660e8400-e29b-41d4-a716-446655440000',
            'client_secret' => null,
            'scopes' => 'https://graph.microsoft.com/.default',
            'base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_interval_minutes' => '15',
        ])
        ->assertRedirect('/admin/graph');

    expect(Setting::get('graph', 'client_secret'))->toBe('existing-secret');
});

test('graph settings page masks client secret', function () {
    Setting::set('graph', 'client_secret', 'my-long-secret-value', encrypted: true);

    $this->actingAs($this->admin)
        ->get('/admin/graph')
        ->assertInertia(fn ($page) => $page
            ->component('admin/Graph')
            ->where('settings.client_secret_masked', '••••••••alue')
        );
});

test('update clears cached graph token', function () {
    Cache::put('msgraph_access_token', 'old-token', 3600);

    $this->actingAs($this->admin)
        ->put('/admin/graph', [
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'client_id' => '660e8400-e29b-41d4-a716-446655440000',
            'client_secret' => 'new-secret',
            'scopes' => 'https://graph.microsoft.com/.default',
            'base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_interval_minutes' => '15',
        ]);

    expect(Cache::has('msgraph_access_token'))->toBeFalse();
});

test('test connection succeeds with valid credentials', function () {
    Setting::set('graph', 'tenant_id', 'test-tenant');
    Setting::set('graph', 'client_id', 'test-client');
    Setting::set('graph', 'client_secret', 'test-secret', encrypted: true);
    Setting::set('graph', 'scopes', 'https://graph.microsoft.com/.default');

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'valid-token',
            'expires_in' => 3600,
        ]),
    ]);

    Cache::forget('msgraph_access_token');

    $this->actingAs($this->admin)
        ->post('/admin/graph/test')
        ->assertOk()
        ->assertJson(['success' => true]);
});

test('test connection fails with invalid credentials', function () {
    Setting::set('graph', 'tenant_id', 'bad-tenant');
    Setting::set('graph', 'client_id', 'bad-client');
    Setting::set('graph', 'client_secret', 'bad-secret', encrypted: true);
    Setting::set('graph', 'scopes', 'https://graph.microsoft.com/.default');

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'Invalid client credentials.',
        ], 401),
    ]);

    Cache::forget('msgraph_access_token');

    $this->actingAs($this->admin)
        ->post('/admin/graph/test')
        ->assertOk()
        ->assertJson(['success' => false]);
});

test('update validates required fields', function () {
    $this->actingAs($this->admin)
        ->put('/admin/graph', [])
        ->assertSessionHasErrors(['tenant_id', 'client_id', 'scopes', 'base_url', 'sync_interval_minutes']);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminGraphControllerTest`
Expected: FAIL — routes, controller don't exist

**Step 3: Create admin routes file**

```php
// routes/admin.php
<?php

use App\Http\Controllers\Admin\AdminGraphController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'approved', 'role:admin'])->prefix('admin')->group(function () {
    Route::redirect('/', '/admin/graph');

    Route::get('graph', [AdminGraphController::class, 'edit'])->name('admin.graph.edit');
    Route::put('graph', [AdminGraphController::class, 'update'])->name('admin.graph.update');
    Route::post('graph/test', [AdminGraphController::class, 'testConnection'])->name('admin.graph.test');
});
```

**Step 4: Register admin routes in bootstrap/app.php**

Modify `bootstrap/app.php` — update `withRouting`:
```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
    then: function () {
        Route::middleware('web')->group(base_path('routes/admin.php'));
    },
)
```

Add `use Illuminate\Support\Facades\Route;` at the top of `bootstrap/app.php`.

**Step 5: Create FormRequest**

```php
// app/Http/Requests/UpdateGraphSettingsRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGraphSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'uuid'],
            'client_id' => ['required', 'uuid'],
            'client_secret' => ['nullable', 'string'],
            'scopes' => ['required', 'string'],
            'base_url' => ['required', 'url'],
            'sync_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
```

**Step 6: Create controller**

```php
// app/Http/Controllers/Admin/AdminGraphController.php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGraphSettingsRequest;
use App\Models\Setting;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class AdminGraphController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function edit(): Response
    {
        $settings = Setting::getGroup('graph');

        $masked = null;
        if ($secret = ($settings['client_secret'] ?? null)) {
            $masked = '••••••••' . substr($secret, -4);
        }

        return Inertia::render('admin/Graph', [
            'settings' => [
                'tenant_id' => $settings['tenant_id'] ?? config('graph.tenant_id'),
                'client_id' => $settings['client_id'] ?? config('graph.client_id'),
                'client_secret_masked' => $masked,
                'scopes' => $settings['scopes'] ?? config('graph.scopes'),
                'base_url' => $settings['base_url'] ?? config('graph.base_url'),
                'sync_interval_minutes' => $settings['sync_interval_minutes'] ?? config('graph.sync_interval_minutes'),
            ],
        ]);
    }

    public function update(UpdateGraphSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('graph', 'tenant_id', $validated['tenant_id']);
        Setting::set('graph', 'client_id', $validated['client_id']);
        Setting::set('graph', 'scopes', $validated['scopes']);
        Setting::set('graph', 'base_url', $validated['base_url']);
        Setting::set('graph', 'sync_interval_minutes', (string) $validated['sync_interval_minutes']);

        if (!empty($validated['client_secret'])) {
            Setting::set('graph', 'client_secret', $validated['client_secret'], encrypted: true);
        }

        Cache::forget('msgraph_access_token');

        $this->activityLog->log($request->user(), ActivityAction::SettingsUpdated, null, [
            'group' => 'graph',
        ]);

        return redirect()->route('admin.graph.edit')->with('success', 'Graph settings updated.');
    }

    public function testConnection(): JsonResponse
    {
        try {
            $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));

            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => Setting::get('graph', 'client_id', config('graph.client_id')),
                    'client_secret' => Setting::get('graph', 'client_secret', config('graph.client_secret')),
                    'scope' => Setting::get('graph', 'scopes', config('graph.scopes')),
                ]
            );

            if ($response->successful() && $response->json('access_token')) {
                return response()->json(['success' => true, 'message' => 'Connection successful.']);
            }

            return response()->json([
                'success' => false,
                'message' => $response->json('error_description', 'Authentication failed.'),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ]);
        }
    }
}
```

**Step 7: Run test to verify it passes**

Run: `php artisan test --filter=AdminGraphControllerTest`
Expected: All tests PASS

**Step 8: Commit**

```bash
git add routes/admin.php bootstrap/app.php app/Http/Controllers/Admin/AdminGraphController.php app/Http/Requests/UpdateGraphSettingsRequest.php tests/Feature/Admin/AdminGraphControllerTest.php
git commit -m "feat: add admin graph settings controller with test connection"
```

---

### Task 8: Admin User Management Controller

**Files:**
- Create: `app/Http/Controllers/Admin/AdminUserController.php`
- Modify: `routes/admin.php`
- Create: `tests/Feature/Admin/AdminUserControllerTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Admin/AdminUserControllerTest.php
<?php

use App\Enums\UserRole;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'approved_at' => now(),
    ]);
});

test('admin can view users list', function () {
    User::factory()->count(3)->create(['approved_at' => now()]);
    User::factory()->count(2)->create(['approved_at' => null]);

    $this->actingAs($this->admin)
        ->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Users')
            ->has('users.data', 6) // 3 + 2 + admin
        );
});

test('pending users are listed first', function () {
    $approved = User::factory()->create(['name' => 'Approved', 'approved_at' => now()]);
    $pending = User::factory()->create(['name' => 'Pending', 'approved_at' => null]);

    $this->actingAs($this->admin)
        ->get('/admin/users')
        ->assertInertia(fn ($page) => $page
            ->where('users.data.0.name', 'Pending')
        );
});

test('admin can approve a user', function () {
    $user = User::factory()->create(['approved_at' => null]);

    $this->actingAs($this->admin)
        ->post("/admin/users/{$user->id}/approve")
        ->assertRedirect();

    $user->refresh();
    expect($user->isApproved())->toBeTrue();
    expect($user->approved_by)->toBe($this->admin->id);
});

test('admin can change user role', function () {
    $user = User::factory()->create([
        'role' => UserRole::Viewer,
        'approved_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->patch("/admin/users/{$user->id}/role", ['role' => 'operator'])
        ->assertRedirect();

    expect($user->refresh()->role)->toBe(UserRole::Operator);
});

test('admin cannot change own role', function () {
    $this->actingAs($this->admin)
        ->patch("/admin/users/{$this->admin->id}/role", ['role' => 'viewer'])
        ->assertForbidden();
});

test('admin can delete a user', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($this->admin)
        ->delete("/admin/users/{$user->id}")
        ->assertRedirect();

    expect(User::find($user->id))->toBeNull();
});

test('admin cannot delete self', function () {
    $this->actingAs($this->admin)
        ->delete("/admin/users/{$this->admin->id}")
        ->assertForbidden();
});

test('non-admin cannot access user management', function () {
    $operator = User::factory()->create([
        'role' => UserRole::Operator,
        'approved_at' => now(),
    ]);

    $this->actingAs($operator)
        ->get('/admin/users')
        ->assertForbidden();
});

test('role validation rejects invalid role', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($this->admin)
        ->patch("/admin/users/{$user->id}/role", ['role' => 'superadmin'])
        ->assertSessionHasErrors('role');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminUserControllerTest`
Expected: FAIL — controller and routes don't exist

**Step 3: Create controller**

```php
// app/Http/Controllers/Admin/AdminUserController.php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function index(): Response
    {
        $users = User::query()
            ->orderByRaw('approved_at IS NOT NULL, approved_at ASC')
            ->orderBy('name')
            ->paginate(25);

        return Inertia::render('admin/Users', [
            'users' => $users,
        ]);
    }

    public function approve(Request $request, User $user): RedirectResponse
    {
        $user->approve($request->user());

        $this->activityLog->log($request->user(), ActivityAction::UserApproved, $user);

        return redirect()->back()->with('success', "User '{$user->name}' approved.");
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            abort(403, 'Cannot change your own role.');
        }

        $validated = $request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
        ]);

        $oldRole = $user->role->value;
        $user->update(['role' => $validated['role']]);

        $this->activityLog->log($request->user(), ActivityAction::UserRoleChanged, $user, [
            'old_role' => $oldRole,
            'new_role' => $validated['role'],
        ]);

        return redirect()->back()->with('success', "Role updated for '{$user->name}'.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            abort(403, 'Cannot delete your own account.');
        }

        $this->activityLog->log($request->user(), ActivityAction::UserDeleted, null, [
            'deleted_user' => $user->name,
            'deleted_email' => $user->email,
        ]);

        $user->delete();

        return redirect()->back()->with('success', "User '{$user->name}' deleted.");
    }
}
```

**Step 4: Add routes**

Add to `routes/admin.php` inside the group:

```php
use App\Http\Controllers\Admin\AdminUserController;

Route::get('users', [AdminUserController::class, 'index'])->name('admin.users.index');
Route::post('users/{user}/approve', [AdminUserController::class, 'approve'])->name('admin.users.approve');
Route::patch('users/{user}/role', [AdminUserController::class, 'updateRole'])->name('admin.users.role');
Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
```

**Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AdminUserControllerTest`
Expected: All tests PASS

**Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/AdminUserController.php routes/admin.php tests/Feature/Admin/AdminUserControllerTest.php
git commit -m "feat: add admin user management with approval and role assignment"
```

---

### Task 9: Admin Sync Settings Controller

**Files:**
- Create: `app/Http/Controllers/Admin/AdminSyncController.php`
- Create: `app/Http/Requests/UpdateSyncSettingsRequest.php`
- Modify: `routes/admin.php`
- Create: `tests/Feature/Admin/AdminSyncControllerTest.php`

**Step 1: Write the failing test**

```php
// tests/Feature/Admin/AdminSyncControllerTest.php
<?php

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\SyncLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'approved_at' => now(),
    ]);
});

test('admin can view sync settings page', function () {
    $this->actingAs($this->admin)
        ->get('/admin/sync')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Sync'));
});

test('sync settings page includes intervals and recent logs', function () {
    Setting::set('sync', 'partners_interval_minutes', '30');
    SyncLog::factory()->count(3)->create(['type' => 'partners']);
    SyncLog::factory()->count(2)->create(['type' => 'guests']);

    $this->actingAs($this->admin)
        ->get('/admin/sync')
        ->assertInertia(fn ($page) => $page
            ->where('intervals.partners_interval_minutes', '30')
            ->has('logs.partners', 3)
            ->has('logs.guests', 2)
        );
});

test('admin can update sync intervals', function () {
    $this->actingAs($this->admin)
        ->put('/admin/sync', [
            'partners_interval_minutes' => 30,
            'guests_interval_minutes' => 60,
        ])
        ->assertRedirect();

    expect(Setting::get('sync', 'partners_interval_minutes'))->toBe('30');
    expect(Setting::get('sync', 'guests_interval_minutes'))->toBe('60');
});

test('admin can trigger a manual sync', function () {
    $this->actingAs($this->admin)
        ->post('/admin/sync/partners/run')
        ->assertOk()
        ->assertJson(['message' => 'Sync started.']);
});

test('trigger sync rejects invalid type', function () {
    $this->actingAs($this->admin)
        ->post('/admin/sync/invalid/run')
        ->assertNotFound();
});

test('sync interval validation', function () {
    $this->actingAs($this->admin)
        ->put('/admin/sync', [
            'partners_interval_minutes' => 0,
            'guests_interval_minutes' => 1441,
        ])
        ->assertSessionHasErrors(['partners_interval_minutes', 'guests_interval_minutes']);
});

test('non-admin cannot access sync settings', function () {
    $operator = User::factory()->create([
        'role' => UserRole::Operator,
        'approved_at' => now(),
    ]);

    $this->actingAs($operator)
        ->get('/admin/sync')
        ->assertForbidden();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminSyncControllerTest`
Expected: FAIL — controller and routes don't exist

**Step 3: Create FormRequest**

```php
// app/Http/Requests/UpdateSyncSettingsRequest.php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSyncSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'partners_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'guests_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
```

**Step 4: Create controller**

```php
// app/Http/Controllers/Admin/AdminSyncController.php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSyncSettingsRequest;
use App\Models\Setting;
use App\Models\SyncLog;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class AdminSyncController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('admin/Sync', [
            'intervals' => [
                'partners_interval_minutes' => Setting::get('sync', 'partners_interval_minutes', config('graph.sync_interval_minutes')),
                'guests_interval_minutes' => Setting::get('sync', 'guests_interval_minutes', config('graph.sync_interval_minutes')),
            ],
            'logs' => [
                'partners' => SyncLog::byType('partners')->recent(10)->get(),
                'guests' => SyncLog::byType('guests')->recent(10)->get(),
            ],
        ]);
    }

    public function update(UpdateSyncSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('sync', 'partners_interval_minutes', (string) $validated['partners_interval_minutes']);
        Setting::set('sync', 'guests_interval_minutes', (string) $validated['guests_interval_minutes']);

        $this->activityLog->log($request->user(), ActivityAction::SettingsUpdated, null, [
            'group' => 'sync',
        ]);

        return redirect()->back()->with('success', 'Sync settings updated.');
    }

    public function run(Request $request, string $type): JsonResponse
    {
        if (!in_array($type, ['partners', 'guests'])) {
            abort(404);
        }

        Artisan::queue("sync:{$type}");

        $this->activityLog->log($request->user(), ActivityAction::SyncTriggered, null, [
            'type' => $type,
        ]);

        return response()->json(['message' => 'Sync started.']);
    }
}
```

**Step 5: Add routes**

Add to `routes/admin.php` inside the group:

```php
use App\Http\Controllers\Admin\AdminSyncController;

Route::get('sync', [AdminSyncController::class, 'edit'])->name('admin.sync.edit');
Route::put('sync', [AdminSyncController::class, 'update'])->name('admin.sync.update');
Route::post('sync/{type}/run', [AdminSyncController::class, 'run'])->name('admin.sync.run');
```

**Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AdminSyncControllerTest`
Expected: All tests PASS

**Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/AdminSyncController.php app/Http/Requests/UpdateSyncSettingsRequest.php routes/admin.php tests/Feature/Admin/AdminSyncControllerTest.php
git commit -m "feat: add admin sync settings controller with manual trigger"
```

---

### Task 10: Refactor Sync Commands for SyncLog and Dynamic Intervals

**Files:**
- Modify: `app/Console/Commands/SyncPartners.php`
- Modify: `app/Console/Commands/SyncGuests.php`
- Modify: `routes/console.php`
- Modify: `tests/Feature/Commands/SyncPartnersTest.php`
- Modify: `tests/Feature/Commands/SyncGuestsTest.php`

**Step 1: Write the new tests**

Add to `tests/Feature/Commands/SyncPartnersTest.php`:

```php
use App\Models\SyncLog;

test('sync:partners creates a SyncLog entry on success', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners' => Http::response(['value' => []]),
    ]);

    $this->artisan('sync:partners')->assertSuccessful();

    $log = SyncLog::where('type', 'partners')->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe('completed');
    expect($log->records_synced)->toBe(0);
    expect($log->completed_at)->not->toBeNull();
});

test('sync:partners creates a failed SyncLog entry on error', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners' => Http::response(
            ['error' => ['code' => 'Forbidden', 'message' => 'Insufficient privileges']],
            403
        ),
    ]);

    $this->artisan('sync:partners')->assertFailed();

    $log = SyncLog::where('type', 'partners')->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe('failed');
    expect($log->error_message)->not->toBeNull();
});
```

Add to `tests/Feature/Commands/SyncGuestsTest.php`:

```php
use App\Models\SyncLog;

test('sync:guests creates a SyncLog entry on success', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users*' => Http::response(['value' => []]),
    ]);

    $this->artisan('sync:guests')->assertSuccessful();

    $log = SyncLog::where('type', 'guests')->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe('completed');
    expect($log->records_synced)->toBe(0);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SyncPartnersTest && php artisan test --filter=SyncGuestsTest`
Expected: New tests FAIL — no SyncLog creation yet

**Step 3: Update SyncPartners command**

Replace `app/Console/Commands/SyncPartners.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\PartnerOrganization;
use App\Models\SyncLog;
use App\Services\CrossTenantPolicyService;
use App\Services\TenantResolverService;
use Illuminate\Console\Command;

class SyncPartners extends Command
{
    protected $signature = 'sync:partners';
    protected $description = 'Sync partner organizations from Microsoft Graph API';

    public function handle(CrossTenantPolicyService $policyService, TenantResolverService $resolver): int
    {
        $log = SyncLog::create([
            'type' => 'partners',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Fetching partner configurations from Graph API...');

            $partners = $policyService->listPartners();
            $synced = 0;

            foreach ($partners as $partner) {
                $tenantId = $partner['tenantId'];

                $displayName = $tenantId;
                $domain = null;
                try {
                    $info = $resolver->resolve($tenantId);
                    $displayName = $info['displayName'] ?? $tenantId;
                    $domain = $info['defaultDomainName'] ?? null;
                } catch (\Throwable $e) {
                    $this->warn("Could not resolve tenant info for {$tenantId}: {$e->getMessage()}");
                }

                $inboundTrust = $partner['inboundTrust'] ?? [];
                $b2bInbound = $partner['b2bCollaborationInbound'] ?? [];
                $b2bOutbound = $partner['b2bCollaborationOutbound'] ?? [];
                $directConnect = $partner['b2bDirectConnectInbound'] ?? [];

                PartnerOrganization::updateOrCreate(
                    ['tenant_id' => $tenantId],
                    [
                        'display_name' => $displayName,
                        'domain' => $domain,
                        'mfa_trust_enabled' => $inboundTrust['isMfaAccepted'] ?? false,
                        'device_trust_enabled' => $inboundTrust['isCompliantDeviceAccepted'] ?? false,
                        'b2b_inbound_enabled' => ($b2bInbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
                        'b2b_outbound_enabled' => ($b2bOutbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
                        'direct_connect_enabled' => ($directConnect['usersAndGroups']['accessType'] ?? '') === 'allowed',
                        'raw_policy_json' => $partner,
                        'last_synced_at' => now(),
                    ]
                );

                $synced++;
            }

            $this->info("Synced {$synced} partner organizations.");

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

**Step 4: Update SyncGuests command**

Replace `app/Console/Commands/SyncGuests.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\InvitationStatus;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\SyncLog;
use App\Services\GuestUserService;
use Illuminate\Console\Command;

class SyncGuests extends Command
{
    protected $signature = 'sync:guests';
    protected $description = 'Sync guest users from Microsoft Graph API';

    public function handle(GuestUserService $guestService): int
    {
        $log = SyncLog::create([
            'type' => 'guests',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Fetching guest users from Graph API...');

            $guests = $guestService->listGuests();
            $synced = 0;

            foreach ($guests as $guest) {
                $email = $guest['mail'] ?? $guest['otherMails'][0] ?? null;
                $domain = $email ? substr($email, strpos($email, '@') + 1) : null;

                $partnerId = null;
                if ($domain) {
                    $partner = PartnerOrganization::where('domain', $domain)->first();
                    $partnerId = $partner?->id;
                }

                GuestUser::updateOrCreate(
                    ['entra_user_id' => $guest['id']],
                    [
                        'email' => $email,
                        'display_name' => $guest['displayName'] ?? $email,
                        'user_principal_name' => $guest['userPrincipalName'] ?? null,
                        'partner_organization_id' => $partnerId,
                        'invitation_status' => InvitationStatus::Accepted,
                        'last_sign_in_at' => isset($guest['signInActivity']['lastSignInDateTime'])
                            ? $guest['signInActivity']['lastSignInDateTime']
                            : null,
                        'last_synced_at' => now(),
                    ]
                );

                $synced++;
            }

            $this->info("Synced {$synced} guest users.");

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

**Step 5: Update scheduler for dynamic intervals**

Replace `routes/console.php`:

```php
<?php

use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$partnersInterval = (int) Setting::get('sync', 'partners_interval_minutes', config('graph.sync_interval_minutes'));
$guestsInterval = (int) Setting::get('sync', 'guests_interval_minutes', config('graph.sync_interval_minutes'));

Schedule::command('sync:partners')->everyMinutes($partnersInterval);
Schedule::command('sync:guests')->everyMinutes($guestsInterval);
```

**Step 6: Run tests**

Run: `php artisan test --filter=SyncPartnersTest && php artisan test --filter=SyncGuestsTest`
Expected: All tests PASS (both old and new)

**Step 7: Commit**

```bash
git add app/Console/Commands/SyncPartners.php app/Console/Commands/SyncGuests.php routes/console.php tests/Feature/Commands/SyncPartnersTest.php tests/Feature/Commands/SyncGuestsTest.php
git commit -m "feat: add SyncLog tracking to sync commands with dynamic intervals"
```

---

### Task 11: Frontend — Admin Layout and Navigation

**Files:**
- Create: `resources/js/layouts/AdminLayout.vue`
- Modify: `resources/js/components/AppSidebar.vue`

**Step 1: Create AdminLayout**

```vue
<!-- resources/js/layouts/AdminLayout.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Cable, Settings2, Users } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, NavItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const adminNavItems: NavItem[] = [
    { title: 'Microsoft Graph', href: '/admin/graph', icon: Cable },
    { title: 'User Management', href: '/admin/users', icon: Users },
    { title: 'Sync Settings', href: '/admin/sync', icon: Settings2 },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="px-4 py-6">
            <Heading
                title="Administration"
                description="System configuration and user management"
            />

            <div class="flex flex-col lg:flex-row lg:space-x-12">
                <aside class="w-full max-w-xl lg:w-48">
                    <nav class="flex flex-col space-y-1 space-x-0" aria-label="Admin">
                        <Button
                            v-for="item in adminNavItems"
                            :key="item.href as string"
                            variant="ghost"
                            :class="[
                                'w-full justify-start',
                                { 'bg-muted': isCurrentOrParentUrl(item.href) },
                            ]"
                            as-child
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" class="mr-2 h-4 w-4" />
                                {{ item.title }}
                            </Link>
                        </Button>
                    </nav>
                </aside>

                <Separator class="my-6 lg:hidden" />

                <div class="flex-1 md:max-w-2xl">
                    <section class="max-w-xl space-y-12">
                        <slot />
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
```

**Step 2: Add Admin nav item to AppSidebar**

Modify `resources/js/components/AppSidebar.vue`:

Add import:
```typescript
import { Activity, Building2, FileStack, LayoutGrid, Settings, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
```

Add computed admin check and conditional nav item:
```typescript
const page = usePage();
const isAdmin = computed(() => page.props.auth.user.role === 'admin');

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        { title: 'Partners', href: '/partners', icon: Building2 },
        { title: 'Guests', href: '/guests', icon: Users },
        { title: 'Templates', href: '/templates', icon: FileStack },
        { title: 'Activity', href: '/activity', icon: Activity },
    ];

    if (isAdmin.value) {
        items.push({ title: 'Admin', href: '/admin/graph', icon: Settings });
    }

    return items;
});
```

Update the template to use `mainNavItems` (it's now a computed ref, but usage stays the same since `<NavMain :items="mainNavItems" />` works with both).

**Step 3: Add `role` to the User TypeScript type**

Modify `resources/js/types/auth.ts` — add `role` to the `User` type:

```typescript
export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role: 'admin' | 'operator' | 'viewer';
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};
```

**Step 4: Verify it compiles**

Run: `npm run types:check`
Expected: No type errors

**Step 5: Commit**

```bash
git add resources/js/layouts/AdminLayout.vue resources/js/components/AppSidebar.vue resources/js/types/auth.ts
git commit -m "feat: add AdminLayout and admin nav item for admin users"
```

---

### Task 12: Frontend — Graph Settings Page

**Files:**
- Create: `resources/js/pages/admin/Graph.vue`

**Step 1: Create the page**

```vue
<!-- resources/js/pages/admin/Graph.vue -->
<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    settings: {
        tenant_id: string | null;
        client_id: string | null;
        client_secret_masked: string | null;
        scopes: string | null;
        base_url: string | null;
        sync_interval_minutes: string | number | null;
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Microsoft Graph', href: '/admin/graph' },
];

const form = useForm({
    tenant_id: props.settings.tenant_id ?? '',
    client_id: props.settings.client_id ?? '',
    client_secret: '',
    scopes: props.settings.scopes ?? '',
    base_url: props.settings.base_url ?? '',
    sync_interval_minutes: props.settings.sync_interval_minutes ?? 15,
});

const submit = () => {
    form.put('/admin/graph');
};

const testResult = ref<{ success: boolean; message: string } | null>(null);
const testLoading = ref(false);

const testConnection = async () => {
    testResult.value = null;
    testLoading.value = true;

    try {
        const response = await fetch('/admin/graph/test', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
        });
        testResult.value = await response.json();
    } catch {
        testResult.value = { success: false, message: 'Request failed.' };
    } finally {
        testLoading.value = false;
    }
};
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <Head title="Microsoft Graph Settings" />

        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="Microsoft Graph Configuration"
                description="Configure credentials for the Microsoft Graph API connection"
            />

            <form @submit.prevent="submit" class="space-y-6">
                <div class="grid gap-2">
                    <Label for="tenant_id">Tenant ID</Label>
                    <Input id="tenant_id" v-model="form.tenant_id" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                    <InputError :message="form.errors.tenant_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="client_id">Client ID</Label>
                    <Input id="client_id" v-model="form.client_id" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                    <InputError :message="form.errors.client_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="client_secret">Client Secret</Label>
                    <Input
                        id="client_secret"
                        type="password"
                        v-model="form.client_secret"
                        :placeholder="props.settings.client_secret_masked ?? 'Enter client secret'"
                    />
                    <p v-if="props.settings.client_secret_masked" class="text-xs text-muted-foreground">
                        Leave blank to keep the current secret.
                    </p>
                    <InputError :message="form.errors.client_secret" />
                </div>

                <div class="grid gap-2">
                    <Label for="scopes">Scopes</Label>
                    <Input id="scopes" v-model="form.scopes" />
                    <InputError :message="form.errors.scopes" />
                </div>

                <div class="grid gap-2">
                    <Label for="base_url">Base URL</Label>
                    <Input id="base_url" v-model="form.base_url" />
                    <InputError :message="form.errors.base_url" />
                </div>

                <div class="grid gap-2">
                    <Label for="sync_interval_minutes">Sync Interval (minutes)</Label>
                    <Input id="sync_interval_minutes" type="number" v-model="form.sync_interval_minutes" min="1" max="1440" />
                    <InputError :message="form.errors.sync_interval_minutes" />
                </div>

                <div class="flex items-center gap-4">
                    <Button :disabled="form.processing">Save</Button>

                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p v-show="form.recentlySuccessful" class="text-sm text-muted-foreground">Saved.</p>
                    </Transition>
                </div>
            </form>

            <div class="border-t pt-6">
                <Heading variant="small" title="Test Connection" description="Verify credentials against Microsoft Graph" />
                <div class="mt-4 flex items-center gap-4">
                    <Button variant="outline" :disabled="testLoading" @click="testConnection">
                        {{ testLoading ? 'Testing...' : 'Test Connection' }}
                    </Button>
                    <p v-if="testResult" :class="testResult.success ? 'text-green-600' : 'text-red-600'" class="text-sm">
                        {{ testResult.message }}
                    </p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
```

**Step 2: Run frontend checks**

Run: `npm run types:check && npm run lint`
Expected: No errors

**Step 3: Commit**

```bash
git add resources/js/pages/admin/Graph.vue
git commit -m "feat: add Graph settings admin page"
```

---

### Task 13: Frontend — User Management Page

**Files:**
- Create: `resources/js/pages/admin/Users.vue`

**Step 1: Create the page**

```vue
<!-- resources/js/pages/admin/Users.vue -->
<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { Paginated } from '@/types/partner';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'operator' | 'viewer';
    approved_at: string | null;
    created_at: string;
};

type Props = {
    users: Paginated<AdminUser>;
};

defineProps<Props>();

const page = usePage();
const currentUserId = computed(() => page.props.auth.user.id);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'User Management', href: '/admin/users' },
];

const approve = (userId: number) => {
    router.post(`/admin/users/${userId}/approve`);
};

const updateRole = (userId: number, role: string) => {
    router.patch(`/admin/users/${userId}/role`, { role });
};

const deleteTarget = ref<AdminUser | null>(null);
const confirmDelete = () => {
    if (deleteTarget.value) {
        router.delete(`/admin/users/${deleteTarget.value.id}`);
        deleteTarget.value = null;
    }
};
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <Head title="User Management" />

        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="User Management"
                description="Manage users, approve accounts, and assign roles"
            />

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="user in users.data" :key="user.id">
                        <TableCell class="font-medium">{{ user.name }}</TableCell>
                        <TableCell>{{ user.email }}</TableCell>
                        <TableCell>
                            <Select
                                :model-value="user.role"
                                :disabled="user.id === currentUserId"
                                @update:model-value="(v: string) => updateRole(user.id, v)"
                            >
                                <SelectTrigger class="w-32">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="admin">Admin</SelectItem>
                                    <SelectItem value="operator">Operator</SelectItem>
                                    <SelectItem value="viewer">Viewer</SelectItem>
                                </SelectContent>
                            </Select>
                        </TableCell>
                        <TableCell>
                            <Badge v-if="user.approved_at" variant="default">Active</Badge>
                            <Badge v-else variant="secondary" class="bg-yellow-100 text-yellow-800">Pending</Badge>
                        </TableCell>
                        <TableCell class="space-x-2">
                            <Button
                                v-if="!user.approved_at"
                                size="sm"
                                @click="approve(user.id)"
                            >
                                Approve
                            </Button>
                            <Button
                                v-if="user.id !== currentUserId"
                                size="sm"
                                variant="destructive"
                                @click="deleteTarget = user"
                            >
                                Delete
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <Dialog :open="!!deleteTarget" @update:open="deleteTarget = null">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete User</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete <span class="font-medium">{{ deleteTarget?.name }}</span>?
                        This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="deleteTarget = null">Cancel</Button>
                    <Button variant="destructive" @click="confirmDelete">Delete</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
```

**Step 2: Run frontend checks**

Run: `npm run types:check && npm run lint`
Expected: No errors. If `Badge`, `Dialog`, `Select`, or `Table` components don't exist yet, install them:
```bash
npx shadcn-vue@latest add badge dialog select table
```

**Step 3: Commit**

```bash
git add resources/js/pages/admin/Users.vue
git commit -m "feat: add User Management admin page"
```

---

### Task 14: Frontend — Sync Settings Page

**Files:**
- Create: `resources/js/pages/admin/Sync.vue`

**Step 1: Create the page**

```vue
<!-- resources/js/pages/admin/Sync.vue -->
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';

type SyncLogEntry = {
    id: string;
    type: string;
    status: string;
    records_synced: number | null;
    error_message: string | null;
    started_at: string;
    completed_at: string | null;
};

type Props = {
    intervals: {
        partners_interval_minutes: string | number;
        guests_interval_minutes: string | number;
    };
    logs: {
        partners: SyncLogEntry[];
        guests: SyncLogEntry[];
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Sync Settings', href: '/admin/sync' },
];

const form = useForm({
    partners_interval_minutes: Number(props.intervals.partners_interval_minutes),
    guests_interval_minutes: Number(props.intervals.guests_interval_minutes),
});

const submit = () => {
    form.put('/admin/sync');
};

const syncing = ref<Record<string, boolean>>({ partners: false, guests: false });

const triggerSync = async (type: 'partners' | 'guests') => {
    syncing.value[type] = true;
    try {
        await fetch(`/admin/sync/${type}/run`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
        });
    } finally {
        syncing.value[type] = false;
    }
};

const statusVariant = (status: string) => {
    if (status === 'completed') return 'default';
    if (status === 'failed') return 'destructive';
    return 'secondary';
};

const formatDuration = (start: string, end: string | null) => {
    if (!end) return '—';
    const ms = new Date(end).getTime() - new Date(start).getTime();
    return `${(ms / 1000).toFixed(1)}s`;
};

const expandedError = ref<string | null>(null);
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <Head title="Sync Settings" />

        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="Sync Settings"
                description="Configure automatic synchronization intervals"
            />

            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid gap-2">
                    <Label for="partners_interval">Partners sync interval (minutes)</Label>
                    <Input id="partners_interval" type="number" v-model="form.partners_interval_minutes" min="1" max="1440" />
                    <InputError :message="form.errors.partners_interval_minutes" />
                </div>

                <div class="grid gap-2">
                    <Label for="guests_interval">Guests sync interval (minutes)</Label>
                    <Input id="guests_interval" type="number" v-model="form.guests_interval_minutes" min="1" max="1440" />
                    <InputError :message="form.errors.guests_interval_minutes" />
                </div>

                <div class="flex items-center gap-4">
                    <Button :disabled="form.processing">Save</Button>
                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p v-show="form.recentlySuccessful" class="text-sm text-muted-foreground">Saved.</p>
                    </Transition>
                </div>
            </form>

            <template v-for="type in (['partners', 'guests'] as const)" :key="type">
                <div class="border-t pt-6">
                    <div class="flex items-center justify-between">
                        <Heading variant="small" :title="`${type.charAt(0).toUpperCase() + type.slice(1)} Sync`" />
                        <Button variant="outline" size="sm" :disabled="syncing[type]" @click="triggerSync(type)">
                            {{ syncing[type] ? 'Syncing...' : 'Sync Now' }}
                        </Button>
                    </div>

                    <Table v-if="logs[type].length" class="mt-4">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Status</TableHead>
                                <TableHead>Records</TableHead>
                                <TableHead>Duration</TableHead>
                                <TableHead>Started</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <template v-for="entry in logs[type]" :key="entry.id">
                                <TableRow>
                                    <TableCell>
                                        <Badge :variant="statusVariant(entry.status)">{{ entry.status }}</Badge>
                                        <button
                                            v-if="entry.error_message"
                                            class="ml-2 text-xs text-red-600 underline"
                                            @click="expandedError = expandedError === entry.id ? null : entry.id"
                                        >
                                            {{ expandedError === entry.id ? 'hide' : 'details' }}
                                        </button>
                                    </TableCell>
                                    <TableCell>{{ entry.records_synced ?? '—' }}</TableCell>
                                    <TableCell>{{ formatDuration(entry.started_at, entry.completed_at) }}</TableCell>
                                    <TableCell>{{ new Date(entry.started_at).toLocaleString() }}</TableCell>
                                </TableRow>
                                <TableRow v-if="expandedError === entry.id">
                                    <TableCell colspan="4" class="bg-red-50 text-sm text-red-700">
                                        {{ entry.error_message }}
                                    </TableCell>
                                </TableRow>
                            </template>
                        </TableBody>
                    </Table>
                    <p v-else class="mt-4 text-sm text-muted-foreground">No sync history yet.</p>
                </div>
            </template>
        </div>
    </AdminLayout>
</template>
```

**Step 2: Run frontend checks**

Run: `npm run types:check && npm run lint`
Expected: No errors

**Step 3: Commit**

```bash
git add resources/js/pages/admin/Sync.vue
git commit -m "feat: add Sync Settings admin page with history"
```

---

### Task 15: Generate Wayfinder Routes and Final Verification

**Step 1: Generate TypeScript route helpers**

Run: `php artisan wayfinder:generate`
Expected: New route files generated under `resources/js/routes/admin/`

**Step 2: Run the full CI check**

Run: `composer run ci:check`
Expected: All lint, format, type checks, and tests pass

**Step 3: Fix any issues**

If any tests or checks fail, fix them before committing.

**Step 4: Final commit**

```bash
git add -A
git commit -m "chore: regenerate Wayfinder routes for admin panel"
```

---

### Task 16: Update User Factory (if needed)

If any existing tests broke due to the `approved_at` column being null by default, update the User factory.

**Files:**
- Modify: `database/factories/UserFactory.php`

Add `'approved_at' => now()` to the factory `definition()` method so existing tests continue to work with "approved" users by default.

This should have been caught and fixed in Task 4 Step 7, but listing here as a catch-all.

---
