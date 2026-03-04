# M365 External Partner Management — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a Laravel+Vue app to manage M365 external partners, cross-tenant access policies, MFA trust, and guest users via Microsoft Graph API.

**Architecture:** Monolithic Laravel 12 + Vue 3/Inertia app. Server-side Graph API calls via service classes. Hybrid data model with local DB cache + Graph as source of truth. 3-tier RBAC (Admin/Operator/Viewer).

**Tech Stack:** Laravel 12, Vue 3, Inertia.js, Tailwind CSS, shadcn-vue, Pest, Microsoft Graph API v1.0

---

## Phase 1: Foundation — Config, Graph Service, Database

### Task 1: Add Graph API Configuration

**Files:**
- Modify: `.env.example`
- Create: `config/graph.php`

**Step 1: Add env vars to `.env.example`**

Append to `.env.example`:

```env
MICROSOFT_GRAPH_TENANT_ID=
MICROSOFT_GRAPH_CLIENT_ID=
MICROSOFT_GRAPH_CLIENT_SECRET=
MICROSOFT_GRAPH_SCOPES="https://graph.microsoft.com/.default"
```

**Step 2: Create config file**

Create `config/graph.php`:

```php
<?php

return [
    'tenant_id' => env('MICROSOFT_GRAPH_TENANT_ID'),
    'client_id' => env('MICROSOFT_GRAPH_CLIENT_ID'),
    'client_secret' => env('MICROSOFT_GRAPH_CLIENT_SECRET'),
    'scopes' => env('MICROSOFT_GRAPH_SCOPES', 'https://graph.microsoft.com/.default'),
    'base_url' => env('MICROSOFT_GRAPH_BASE_URL', 'https://graph.microsoft.com/v1.0'),
    'sync_interval_minutes' => env('MICROSOFT_GRAPH_SYNC_INTERVAL', 15),
];
```

**Step 3: Commit**

```bash
git add .env.example config/graph.php
git commit -m "feat: add Microsoft Graph API configuration"
```

---

### Task 2: Create MicrosoftGraphService

**Files:**
- Create: `app/Services/MicrosoftGraphService.php`
- Create: `tests/Unit/Services/MicrosoftGraphServiceTest.php`

**Step 1: Write the failing test**

Create `tests/Unit/Services/MicrosoftGraphServiceTest.php`:

```php
<?php

use App\Services\MicrosoftGraphService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant-id',
        'graph.client_id' => 'test-client-id',
        'graph.client_secret' => 'test-client-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
    ]);
});

test('it acquires an access token via client credentials', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token-123',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
    ]);

    Cache::forget('msgraph_access_token');

    $service = app(MicrosoftGraphService::class);
    $token = $service->getAccessToken();

    expect($token)->toBe('fake-token-123');
});

test('it caches the access token', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token-456',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
    ]);

    Cache::forget('msgraph_access_token');

    $service = app(MicrosoftGraphService::class);
    $service->getAccessToken();
    $service->getAccessToken();

    Http::assertSentCount(1);
});

test('it makes authenticated GET requests to graph api', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/*' => Http::response([
            'value' => [['id' => '123']],
        ]),
    ]);

    Cache::forget('msgraph_access_token');

    $service = app(MicrosoftGraphService::class);
    $response = $service->get('/users');

    expect($response['value'])->toHaveCount(1);
    expect($response['value'][0]['id'])->toBe('123');
});

test('it makes authenticated POST requests to graph api', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/*' => Http::response([
            'id' => 'new-id',
        ], 201),
    ]);

    Cache::forget('msgraph_access_token');

    $service = app(MicrosoftGraphService::class);
    $response = $service->post('/invitations', ['invitedUserEmailAddress' => 'test@example.com']);

    expect($response['id'])->toBe('new-id');
});

test('it makes authenticated PATCH requests to graph api', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/*' => Http::response([], 204),
    ]);

    Cache::forget('msgraph_access_token');

    $service = app(MicrosoftGraphService::class);
    $response = $service->patch('/users/123', ['displayName' => 'Updated']);

    expect($response)->toBeEmpty();
});

test('it makes authenticated DELETE requests to graph api', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/*' => Http::response([], 204),
    ]);

    Cache::forget('msgraph_access_token');

    $service = app(MicrosoftGraphService::class);
    $response = $service->delete('/users/123');

    expect($response)->toBeEmpty();
});

test('it throws on graph api error responses', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/*' => Http::response([
            'error' => [
                'code' => 'Request_ResourceNotFound',
                'message' => 'Resource not found.',
            ],
        ], 404),
    ]);

    Cache::forget('msgraph_access_token');

    $service = app(MicrosoftGraphService::class);
    $service->get('/users/nonexistent');
})->throws(\App\Exceptions\GraphApiException::class);
```

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/MicrosoftGraphServiceTest.php`
Expected: FAIL — class not found

**Step 3: Create the GraphApiException**

Create `app/Exceptions/GraphApiException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class GraphApiException extends RuntimeException
{
    public readonly string $graphErrorCode;
    public readonly array $graphError;

    public function __construct(string $message, int $httpStatus, string $graphErrorCode = '', array $graphError = [])
    {
        parent::__construct($message, $httpStatus);
        $this->graphErrorCode = $graphErrorCode;
        $this->graphError = $graphError;
    }

    public static function fromResponse(int $status, array $body): self
    {
        $error = $body['error'] ?? [];
        $message = $error['message'] ?? 'Unknown Graph API error';
        $code = $error['code'] ?? '';

        return new self($message, $status, $code, $error);
    }
}
```

**Step 4: Write minimal implementation**

Create `app/Services/MicrosoftGraphService.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\GraphApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MicrosoftGraphService
{
    public function getAccessToken(): string
    {
        return Cache::remember('msgraph_access_token', 3500, function () {
            $tenantId = config('graph.tenant_id');

            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('graph.client_id'),
                    'client_secret' => config('graph.client_secret'),
                    'scope' => config('graph.scopes'),
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
        $url = config('graph.base_url') . $path;

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

**Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/Services/MicrosoftGraphServiceTest.php`
Expected: All 7 tests PASS

**Step 6: Commit**

```bash
git add app/Services/MicrosoftGraphService.php app/Exceptions/GraphApiException.php tests/Unit/Services/MicrosoftGraphServiceTest.php
git commit -m "feat: add MicrosoftGraphService with token management and HTTP methods"
```

---

### Task 3: Add Role Column to Users & RBAC Middleware

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_role_to_users_table.php`
- Modify: `app/Models/User.php`
- Create: `app/Enums/UserRole.php`
- Create: `app/Http/Middleware/CheckRole.php`
- Modify: `bootstrap/app.php`
- Create: `tests/Feature/Middleware/CheckRoleTest.php`

**Step 1: Create the enum**

Create `app/Enums/UserRole.php`:

```php
<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function canManage(): bool
    {
        return match ($this) {
            self::Admin, self::Operator => true,
            self::Viewer => false,
        };
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
```

**Step 2: Create migration**

Run: `php artisan make:migration add_role_to_users_table`

Migration content:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
```

**Step 3: Update User model**

Add to `app/Models/User.php`:
- Add `'role'` to `$fillable`
- Add cast: `'role' => UserRole::class`
- Import `App\Enums\UserRole`

**Step 4: Write failing middleware test**

Create `tests/Feature/Middleware/CheckRoleTest.php`:

```php
<?php

use App\Models\User;
use App\Enums\UserRole;

test('admin can access admin routes', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($user)
        ->get('/test-admin-route')
        ->assertOk();
});

test('viewer cannot access admin routes', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/test-admin-route')
        ->assertForbidden();
});

test('operator can access manage routes', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);

    $this->actingAs($user)
        ->get('/test-manage-route')
        ->assertOk();
});

test('viewer cannot access manage routes', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get('/test-manage-route')
        ->assertForbidden();
});
```

**Step 5: Create CheckRole middleware**

Create `app/Http/Middleware/CheckRole.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role->value, $roles)) {
            abort(403, 'Insufficient permissions.');
        }

        return $next($request);
    }
}
```

**Step 6: Register middleware alias in `bootstrap/app.php`**

Add inside `->withMiddleware(function (Middleware $middleware): void {`:

```php
$middleware->alias([
    'role' => \App\Http\Middleware\CheckRole::class,
]);
```

**Step 7: Add test routes for middleware testing**

Add to `tests/Feature/Middleware/CheckRoleTest.php` a `beforeEach`:

```php
beforeEach(function () {
    Route::middleware(['auth', 'role:admin'])->get('/test-admin-route', fn () => 'ok');
    Route::middleware(['auth', 'role:admin,operator'])->get('/test-manage-route', fn () => 'ok');
});
```

Add `use Illuminate\Support\Facades\Route;` at the top.

**Step 8: Update UserFactory**

In `database/factories/UserFactory.php`, add `'role' => 'viewer'` to the `definition()` return array.

**Step 9: Run tests**

Run: `php artisan test tests/Feature/Middleware/CheckRoleTest.php`
Expected: All 4 tests PASS

**Step 10: Commit**

```bash
git add app/Enums/UserRole.php app/Http/Middleware/CheckRole.php app/Models/User.php bootstrap/app.php database/migrations/*add_role* database/factories/UserFactory.php tests/Feature/Middleware/CheckRoleTest.php
git commit -m "feat: add 3-tier RBAC with UserRole enum and CheckRole middleware"
```

---

### Task 4: Create Database Migrations for Core Tables

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_partner_organizations_table.php`
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_guest_users_table.php`
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_partner_templates_table.php`
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_activity_log_table.php`

**Step 1: Create partner_organizations migration**

Run: `php artisan make:migration create_partner_organizations_table`

```php
public function up(): void
{
    Schema::create('partner_organizations', function (Blueprint $table) {
        $table->id();
        $table->string('tenant_id')->unique();
        $table->string('display_name');
        $table->string('domain')->nullable();
        $table->string('category')->default('other'); // vendor, contractor, strategic_partner, customer, other
        $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->text('notes')->nullable();
        $table->boolean('b2b_inbound_enabled')->default(false);
        $table->boolean('b2b_outbound_enabled')->default(false);
        $table->boolean('mfa_trust_enabled')->default(false);
        $table->boolean('device_trust_enabled')->default(false);
        $table->boolean('direct_connect_enabled')->default(false);
        $table->json('raw_policy_json')->nullable();
        $table->timestamp('last_synced_at')->nullable();
        $table->timestamps();
    });
}
```

**Step 2: Create guest_users migration**

Run: `php artisan make:migration create_guest_users_table`

```php
public function up(): void
{
    Schema::create('guest_users', function (Blueprint $table) {
        $table->id();
        $table->string('entra_user_id')->unique();
        $table->string('email');
        $table->string('display_name')->nullable();
        $table->string('user_principal_name')->nullable();
        $table->foreignId('partner_organization_id')->nullable()->constrained('partner_organizations')->nullOnDelete();
        $table->string('invitation_status')->default('pending_acceptance'); // pending_acceptance, accepted, failed
        $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('invited_at')->nullable();
        $table->timestamp('last_sign_in_at')->nullable();
        $table->boolean('account_enabled')->default(true);
        $table->timestamp('last_synced_at')->nullable();
        $table->timestamps();
    });
}
```

**Step 3: Create partner_templates migration**

Run: `php artisan make:migration create_partner_templates_table`

```php
public function up(): void
{
    Schema::create('partner_templates', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->text('description')->nullable();
        $table->json('policy_config');
        $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
    });
}
```

**Step 4: Create activity_log migration**

Run: `php artisan make:migration create_activity_log_table`

```php
public function up(): void
{
    Schema::create('activity_log', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->string('action'); // partner_created, partner_updated, etc.
        $table->string('subject_type')->nullable();
        $table->unsignedBigInteger('subject_id')->nullable();
        $table->json('details')->nullable();
        $table->timestamp('created_at')->nullable();

        $table->index(['subject_type', 'subject_id']);
    });
}
```

**Step 5: Run migrations**

Run: `php artisan migrate`
Expected: All migrations run successfully

**Step 6: Commit**

```bash
git add database/migrations/
git commit -m "feat: add migrations for partner_organizations, guest_users, partner_templates, activity_log"
```

---

### Task 5: Create Eloquent Models

**Files:**
- Create: `app/Models/PartnerOrganization.php`
- Create: `app/Models/GuestUser.php`
- Create: `app/Models/PartnerTemplate.php`
- Create: `app/Models/ActivityLog.php`
- Create: `app/Enums/PartnerCategory.php`
- Create: `app/Enums/InvitationStatus.php`
- Create: `app/Enums/ActivityAction.php`
- Create: `database/factories/PartnerOrganizationFactory.php`
- Create: `database/factories/GuestUserFactory.php`
- Create: `database/factories/PartnerTemplateFactory.php`
- Create: `tests/Unit/Models/PartnerOrganizationTest.php`

**Step 1: Create enums**

Create `app/Enums/PartnerCategory.php`:

```php
<?php

namespace App\Enums;

enum PartnerCategory: string
{
    case Vendor = 'vendor';
    case Contractor = 'contractor';
    case StrategicPartner = 'strategic_partner';
    case Customer = 'customer';
    case Other = 'other';
}
```

Create `app/Enums/InvitationStatus.php`:

```php
<?php

namespace App\Enums;

enum InvitationStatus: string
{
    case PendingAcceptance = 'pending_acceptance';
    case Accepted = 'accepted';
    case Failed = 'failed';
}
```

Create `app/Enums/ActivityAction.php`:

```php
<?php

namespace App\Enums;

enum ActivityAction: string
{
    case PartnerCreated = 'partner_created';
    case PartnerUpdated = 'partner_updated';
    case PartnerDeleted = 'partner_deleted';
    case GuestInvited = 'guest_invited';
    case GuestRemoved = 'guest_removed';
    case PolicyChanged = 'policy_changed';
    case TemplateCreated = 'template_created';
    case SyncCompleted = 'sync_completed';
}
```

**Step 2: Create models**

Create `app/Models/PartnerOrganization.php`:

```php
<?php

namespace App\Models;

use App\Enums\PartnerCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerOrganization extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'display_name', 'domain', 'category', 'owner_user_id', 'notes',
        'b2b_inbound_enabled', 'b2b_outbound_enabled', 'mfa_trust_enabled',
        'device_trust_enabled', 'direct_connect_enabled', 'raw_policy_json', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => PartnerCategory::class,
            'b2b_inbound_enabled' => 'boolean',
            'b2b_outbound_enabled' => 'boolean',
            'mfa_trust_enabled' => 'boolean',
            'device_trust_enabled' => 'boolean',
            'direct_connect_enabled' => 'boolean',
            'raw_policy_json' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function guestUsers(): HasMany
    {
        return $this->hasMany(GuestUser::class, 'partner_organization_id');
    }
}
```

Create `app/Models/GuestUser.php`:

```php
<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'entra_user_id', 'email', 'display_name', 'user_principal_name',
        'partner_organization_id', 'invitation_status', 'invited_by_user_id',
        'invited_at', 'last_sign_in_at', 'account_enabled', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'invitation_status' => InvitationStatus::class,
            'invited_at' => 'datetime',
            'last_sign_in_at' => 'datetime',
            'account_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function partnerOrganization(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
```

Create `app/Models/PartnerTemplate.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'policy_config', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'policy_config' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
```

Create `app/Models/ActivityLog.php`:

```php
<?php

namespace App\Models;

use App\Enums\ActivityAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $table = 'activity_log';

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'details', 'created_at'];

    protected function casts(): array
    {
        return [
            'action' => ActivityAction::class,
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
```

**Step 3: Create factories**

Create `database/factories/PartnerOrganizationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\PartnerCategory;
use App\Models\PartnerOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PartnerOrganizationFactory extends Factory
{
    protected $model = PartnerOrganization::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Str::uuid()->toString(),
            'display_name' => fake()->company(),
            'domain' => fake()->domainName(),
            'category' => fake()->randomElement(PartnerCategory::cases()),
            'b2b_inbound_enabled' => fake()->boolean(),
            'b2b_outbound_enabled' => fake()->boolean(),
            'mfa_trust_enabled' => fake()->boolean(),
            'device_trust_enabled' => false,
            'direct_connect_enabled' => false,
        ];
    }
}
```

Create `database/factories/GuestUserFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Models\GuestUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GuestUserFactory extends Factory
{
    protected $model = GuestUser::class;

    public function definition(): array
    {
        $email = fake()->safeEmail();
        return [
            'entra_user_id' => Str::uuid()->toString(),
            'email' => $email,
            'display_name' => fake()->name(),
            'user_principal_name' => str_replace('@', '_', $email) . '#EXT#@contoso.onmicrosoft.com',
            'invitation_status' => InvitationStatus::Accepted,
            'account_enabled' => true,
        ];
    }
}
```

Create `database/factories/PartnerTemplateFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\PartnerTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class PartnerTemplateFactory extends Factory
{
    protected $model = PartnerTemplate::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'policy_config' => [
                'b2b_inbound_enabled' => true,
                'b2b_outbound_enabled' => true,
                'mfa_trust_enabled' => true,
                'device_trust_enabled' => false,
                'direct_connect_enabled' => false,
            ],
        ];
    }
}
```

**Step 4: Write model relationship test**

Create `tests/Unit/Models/PartnerOrganizationTest.php`:

```php
<?php

use App\Models\PartnerOrganization;
use App\Models\GuestUser;
use App\Models\User;
use App\Enums\PartnerCategory;

test('partner organization belongs to an owner', function () {
    $user = User::factory()->create();
    $partner = PartnerOrganization::factory()->create(['owner_user_id' => $user->id]);

    expect($partner->owner->id)->toBe($user->id);
});

test('partner organization has many guest users', function () {
    $partner = PartnerOrganization::factory()->create();
    GuestUser::factory()->count(3)->create(['partner_organization_id' => $partner->id]);

    expect($partner->guestUsers)->toHaveCount(3);
});

test('partner organization casts category to enum', function () {
    $partner = PartnerOrganization::factory()->create(['category' => 'vendor']);

    expect($partner->category)->toBe(PartnerCategory::Vendor);
});
```

**Step 5: Run tests**

Run: `php artisan test tests/Unit/Models/PartnerOrganizationTest.php`
Expected: All 3 tests PASS

**Step 6: Commit**

```bash
git add app/Enums/ app/Models/ database/factories/ tests/Unit/Models/
git commit -m "feat: add Eloquent models, enums, and factories for all core tables"
```

---

## Phase 2: Graph API Service Layer

### Task 6: Create CrossTenantPolicyService

**Files:**
- Create: `app/Services/CrossTenantPolicyService.php`
- Create: `tests/Unit/Services/CrossTenantPolicyServiceTest.php`

**Step 1: Write failing tests**

Create `tests/Unit/Services/CrossTenantPolicyServiceTest.php`:

```php
<?php

use App\Services\CrossTenantPolicyService;
use App\Services\MicrosoftGraphService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

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

test('it lists all partner configurations', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners' => Http::response([
            'value' => [
                ['tenantId' => 'tenant-1', 'inboundTrust' => ['isMfaAccepted' => true]],
                ['tenantId' => 'tenant-2', 'inboundTrust' => null],
            ],
        ]),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $partners = $service->listPartners();

    expect($partners)->toHaveCount(2);
    expect($partners[0]['tenantId'])->toBe('tenant-1');
});

test('it gets a single partner configuration', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/tenant-1' => Http::response([
            'tenantId' => 'tenant-1',
            'inboundTrust' => ['isMfaAccepted' => true],
        ]),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $partner = $service->getPartner('tenant-1');

    expect($partner['tenantId'])->toBe('tenant-1');
    expect($partner['inboundTrust']['isMfaAccepted'])->toBeTrue();
});

test('it creates a partner configuration', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners' => Http::response([
            'tenantId' => 'new-tenant',
        ], 201),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $result = $service->createPartner('new-tenant', [
        'inboundTrust' => ['isMfaAccepted' => true],
    ]);

    expect($result['tenantId'])->toBe('new-tenant');
});

test('it updates a partner configuration', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/tenant-1' => Http::response([], 204),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $service->updatePartner('tenant-1', [
        'inboundTrust' => ['isMfaAccepted' => false],
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH');
});

test('it deletes a partner configuration', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/tenant-1' => Http::response([], 204),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $service->deletePartner('tenant-1');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

test('it gets default cross-tenant policy', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/default' => Http::response([
            'inboundTrust' => ['isMfaAccepted' => false],
            'b2bCollaborationInbound' => ['usersAndGroups' => ['accessType' => 'allowed']],
        ]),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $defaults = $service->getDefaults();

    expect($defaults)->toHaveKey('inboundTrust');
});
```

**Step 2: Write implementation**

Create `app/Services/CrossTenantPolicyService.php`:

```php
<?php

namespace App\Services;

class CrossTenantPolicyService
{
    public function __construct(private MicrosoftGraphService $graph) {}

    public function listPartners(): array
    {
        $response = $this->graph->get('/policies/crossTenantAccessPolicy/partners');
        return $response['value'] ?? [];
    }

    public function getPartner(string $tenantId): array
    {
        return $this->graph->get("/policies/crossTenantAccessPolicy/partners/{$tenantId}");
    }

    public function createPartner(string $tenantId, array $config = []): array
    {
        return $this->graph->post('/policies/crossTenantAccessPolicy/partners', [
            'tenantId' => $tenantId,
            ...$config,
        ]);
    }

    public function updatePartner(string $tenantId, array $config): array
    {
        return $this->graph->patch("/policies/crossTenantAccessPolicy/partners/{$tenantId}", $config);
    }

    public function deletePartner(string $tenantId): array
    {
        return $this->graph->delete("/policies/crossTenantAccessPolicy/partners/{$tenantId}");
    }

    public function getDefaults(): array
    {
        return $this->graph->get('/policies/crossTenantAccessPolicy/default');
    }

    public function updateDefaults(array $config): array
    {
        return $this->graph->patch('/policies/crossTenantAccessPolicy/default', $config);
    }
}
```

**Step 3: Run tests**

Run: `php artisan test tests/Unit/Services/CrossTenantPolicyServiceTest.php`
Expected: All 6 tests PASS

**Step 4: Commit**

```bash
git add app/Services/CrossTenantPolicyService.php tests/Unit/Services/CrossTenantPolicyServiceTest.php
git commit -m "feat: add CrossTenantPolicyService for partner policy CRUD"
```

---

### Task 7: Create GuestUserService

**Files:**
- Create: `app/Services/GuestUserService.php`
- Create: `tests/Unit/Services/GuestUserServiceTest.php`

**Step 1: Write failing tests**

Create `tests/Unit/Services/GuestUserServiceTest.php`:

```php
<?php

use App\Services\GuestUserService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

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

test('it lists guest users', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users*' => Http::response([
            'value' => [
                ['id' => 'u1', 'displayName' => 'Guest One', 'userType' => 'Guest'],
                ['id' => 'u2', 'displayName' => 'Guest Two', 'userType' => 'Guest'],
            ],
        ]),
    ]);

    $service = app(GuestUserService::class);
    $guests = $service->listGuests();

    expect($guests)->toHaveCount(2);
});

test('it creates an invitation', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/invitations' => Http::response([
            'id' => 'inv-1',
            'invitedUserEmailAddress' => 'guest@partner.com',
            'status' => 'PendingAcceptance',
            'invitedUser' => ['id' => 'user-id-1'],
        ], 201),
    ]);

    $service = app(GuestUserService::class);
    $result = $service->invite('guest@partner.com', 'https://myapp.com');

    expect($result['invitedUserEmailAddress'])->toBe('guest@partner.com');
    expect($result['invitedUser']['id'])->toBe('user-id-1');
});

test('it gets a single guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1*' => Http::response([
            'id' => 'u1',
            'displayName' => 'Guest One',
            'mail' => 'guest@partner.com',
        ]),
    ]);

    $service = app(GuestUserService::class);
    $user = $service->getUser('u1');

    expect($user['displayName'])->toBe('Guest One');
});

test('it deletes a guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1' => Http::response([], 204),
    ]);

    $service = app(GuestUserService::class);
    $service->deleteUser('u1');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});
```

**Step 2: Write implementation**

Create `app/Services/GuestUserService.php`:

```php
<?php

namespace App\Services;

class GuestUserService
{
    private const GUEST_SELECT_FIELDS = 'id,displayName,mail,userPrincipalName,userType,accountEnabled,createdDateTime,externalUserState,signInActivity';

    public function __construct(private MicrosoftGraphService $graph) {}

    public function listGuests(): array
    {
        $response = $this->graph->get('/users', [
            '$filter' => "userType eq 'Guest'",
            '$select' => self::GUEST_SELECT_FIELDS,
            '$top' => 999,
        ]);

        return $response['value'] ?? [];
    }

    public function getUser(string $userId): array
    {
        return $this->graph->get("/users/{$userId}", [
            '$select' => self::GUEST_SELECT_FIELDS,
        ]);
    }

    public function invite(string $email, string $redirectUrl, ?string $customMessage = null, bool $sendEmail = true): array
    {
        $body = [
            'invitedUserEmailAddress' => $email,
            'inviteRedirectUrl' => $redirectUrl,
            'sendInvitationMessage' => $sendEmail,
        ];

        if ($customMessage) {
            $body['invitedUserMessageInfo'] = [
                'customizedMessageBody' => $customMessage,
            ];
        }

        return $this->graph->post('/invitations', $body);
    }

    public function deleteUser(string $userId): array
    {
        return $this->graph->delete("/users/{$userId}");
    }

    public function updateUser(string $userId, array $data): array
    {
        return $this->graph->patch("/users/{$userId}", $data);
    }
}
```

**Step 3: Run tests**

Run: `php artisan test tests/Unit/Services/GuestUserServiceTest.php`
Expected: All 4 tests PASS

**Step 4: Commit**

```bash
git add app/Services/GuestUserService.php tests/Unit/Services/GuestUserServiceTest.php
git commit -m "feat: add GuestUserService for invitations and guest user management"
```

---

### Task 8: Create TenantResolverService

**Files:**
- Create: `app/Services/TenantResolverService.php`
- Create: `tests/Unit/Services/TenantResolverServiceTest.php`

**Step 1: Write failing tests**

Create `tests/Unit/Services/TenantResolverServiceTest.php`:

```php
<?php

use App\Services\TenantResolverService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

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

test('it resolves tenant info by tenant id', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/tenantRelationships/findTenantInformationByTenantId*' => Http::response([
            'tenantId' => 'abc-123',
            'displayName' => 'Contoso Ltd',
            'defaultDomainName' => 'contoso.com',
        ]),
    ]);

    $service = app(TenantResolverService::class);
    $info = $service->resolve('abc-123');

    expect($info['displayName'])->toBe('Contoso Ltd');
    expect($info['defaultDomainName'])->toBe('contoso.com');
});

test('it validates tenant id format', function () {
    $service = app(TenantResolverService::class);

    expect($service->isValidTenantId('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
    expect($service->isValidTenantId('not-a-guid'))->toBeFalse();
    expect($service->isValidTenantId(''))->toBeFalse();
});
```

**Step 2: Write implementation**

Create `app/Services/TenantResolverService.php`:

```php
<?php

namespace App\Services;

class TenantResolverService
{
    public function __construct(private MicrosoftGraphService $graph) {}

    public function resolve(string $tenantId): array
    {
        return $this->graph->get("/tenantRelationships/findTenantInformationByTenantId(tenantId='{$tenantId}')");
    }

    public function isValidTenantId(string $tenantId): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId);
    }
}
```

**Step 3: Run tests**

Run: `php artisan test tests/Unit/Services/TenantResolverServiceTest.php`
Expected: All 2 tests PASS

**Step 4: Commit**

```bash
git add app/Services/TenantResolverService.php tests/Unit/Services/TenantResolverServiceTest.php
git commit -m "feat: add TenantResolverService for tenant ID lookup"
```

---

### Task 9: Create ActivityLogService

**Files:**
- Create: `app/Services/ActivityLogService.php`
- Create: `tests/Unit/Services/ActivityLogServiceTest.php`

**Step 1: Write failing test**

Create `tests/Unit/Services/ActivityLogServiceTest.php`:

```php
<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\ActivityLogService;

test('it logs an activity', function () {
    $user = User::factory()->create();
    $partner = PartnerOrganization::factory()->create();

    $service = app(ActivityLogService::class);
    $service->log(
        user: $user,
        action: ActivityAction::PartnerCreated,
        subject: $partner,
        details: ['tenant_id' => $partner->tenant_id],
    );

    $log = ActivityLog::first();
    expect($log->action)->toBe(ActivityAction::PartnerCreated);
    expect($log->user_id)->toBe($user->id);
    expect($log->subject_type)->toBe(PartnerOrganization::class);
    expect($log->subject_id)->toBe($partner->id);
    expect($log->details['tenant_id'])->toBe($partner->tenant_id);
});

test('it retrieves recent activity', function () {
    $user = User::factory()->create();
    $service = app(ActivityLogService::class);

    for ($i = 0; $i < 25; $i++) {
        $service->log($user, ActivityAction::SyncCompleted, details: ['count' => $i]);
    }

    $recent = $service->recent(20);
    expect($recent)->toHaveCount(20);
});
```

**Step 2: Write implementation**

Create `app/Services/ActivityLogService.php`:

```php
<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ActivityLogService
{
    public function log(User $user, ActivityAction $action, ?Model $subject = null, array $details = []): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    public function recent(int $limit = 20): Collection
    {
        return ActivityLog::with('user')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function forSubject(Model $subject): Collection
    {
        return ActivityLog::where('subject_type', get_class($subject))
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('created_at')
            ->get();
    }
}
```

**Step 3: Run tests**

Run: `php artisan test tests/Unit/Services/ActivityLogServiceTest.php`
Expected: All 2 tests PASS

**Step 4: Commit**

```bash
git add app/Services/ActivityLogService.php tests/Unit/Services/ActivityLogServiceTest.php
git commit -m "feat: add ActivityLogService for audit trail"
```

---

## Phase 3: Partner Management Backend

### Task 10: Create PartnerOrganization Controller + Routes

**Files:**
- Create: `app/Http/Controllers/PartnerOrganizationController.php`
- Create: `app/Http/Requests/StorePartnerRequest.php`
- Create: `app/Http/Requests/UpdatePartnerRequest.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/PartnerOrganizationTest.php`

**Step 1: Write failing tests**

Create `tests/Feature/PartnerOrganizationTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Models\PartnerOrganization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

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

test('guests cannot access partners index', function () {
    $this->get(route('partners.index'))->assertRedirect(route('login'));
});

test('viewers can see partners list', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);
    PartnerOrganization::factory()->count(3)->create();

    $this->actingAs($user)
        ->get(route('partners.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('partners/Index')
            ->has('partners.data', 3)
        );
});

test('viewers cannot create partners', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get(route('partners.create'))
        ->assertForbidden();
});

test('operators can create partners', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/tenantRelationships/findTenantInformationByTenantId*' => Http::response([
            'tenantId' => '550e8400-e29b-41d4-a716-446655440000',
            'displayName' => 'Test Corp',
            'defaultDomainName' => 'testcorp.com',
        ]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners' => Http::response([
            'tenantId' => '550e8400-e29b-41d4-a716-446655440000',
        ], 201),
    ]);

    $this->actingAs($user)
        ->post(route('partners.store'), [
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'category' => 'vendor',
            'notes' => 'Test partner',
            'mfa_trust_enabled' => true,
            'b2b_inbound_enabled' => true,
            'b2b_outbound_enabled' => false,
            'device_trust_enabled' => false,
            'direct_connect_enabled' => false,
        ])
        ->assertRedirect(route('partners.index'));

    expect(PartnerOrganization::count())->toBe(1);
    expect(PartnerOrganization::first()->display_name)->toBe('Test Corp');
});

test('viewers can see partner detail', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);
    $partner = PartnerOrganization::factory()->create();

    $this->actingAs($user)
        ->get(route('partners.show', $partner))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('partners/Show')
            ->has('partner')
        );
});

test('operators can update partner policy', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'abc-123']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/abc-123' => Http::response([], 204),
    ]);

    $this->actingAs($user)
        ->patch(route('partners.update', $partner), [
            'category' => 'strategic_partner',
            'notes' => 'Updated notes',
            'mfa_trust_enabled' => true,
            'b2b_inbound_enabled' => true,
            'b2b_outbound_enabled' => true,
            'device_trust_enabled' => false,
            'direct_connect_enabled' => false,
        ])
        ->assertRedirect();

    expect($partner->fresh()->category->value)->toBe('strategic_partner');
});

test('only admins can delete partners', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'abc-123']);

    $this->actingAs($operator)
        ->delete(route('partners.destroy', $partner))
        ->assertForbidden();
});

test('admins can delete partners', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'abc-123']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/abc-123' => Http::response([], 204),
    ]);

    $this->actingAs($admin)
        ->delete(route('partners.destroy', $partner))
        ->assertRedirect(route('partners.index'));

    expect(PartnerOrganization::count())->toBe(0);
});
```

**Step 2: Create form requests**

Create `app/Http/Requests/StorePartnerRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\PartnerCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->canManage();
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'string', 'uuid', 'unique:partner_organizations,tenant_id'],
            'category' => ['required', Rule::enum(PartnerCategory::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'mfa_trust_enabled' => ['boolean'],
            'b2b_inbound_enabled' => ['boolean'],
            'b2b_outbound_enabled' => ['boolean'],
            'device_trust_enabled' => ['boolean'],
            'direct_connect_enabled' => ['boolean'],
            'template_id' => ['nullable', 'exists:partner_templates,id'],
        ];
    }
}
```

Create `app/Http/Requests/UpdatePartnerRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\PartnerCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->canManage();
    }

    public function rules(): array
    {
        return [
            'category' => ['sometimes', Rule::enum(PartnerCategory::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_user_id' => ['nullable', 'exists:users,id'],
            'mfa_trust_enabled' => ['boolean'],
            'b2b_inbound_enabled' => ['boolean'],
            'b2b_outbound_enabled' => ['boolean'],
            'device_trust_enabled' => ['boolean'],
            'direct_connect_enabled' => ['boolean'],
        ];
    }
}
```

**Step 3: Create controller**

Create `app/Http/Controllers/PartnerOrganizationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\StorePartnerRequest;
use App\Http\Requests\UpdatePartnerRequest;
use App\Models\PartnerOrganization;
use App\Models\PartnerTemplate;
use App\Services\ActivityLogService;
use App\Services\CrossTenantPolicyService;
use App\Services\TenantResolverService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PartnerOrganizationController extends Controller
{
    public function __construct(
        private CrossTenantPolicyService $policyService,
        private TenantResolverService $tenantResolver,
        private ActivityLogService $activityLog,
    ) {}

    public function index(): Response
    {
        $partners = PartnerOrganization::with('owner')
            ->orderBy('display_name')
            ->paginate(25);

        return Inertia::render('partners/Index', [
            'partners' => $partners,
        ]);
    }

    public function create(): Response
    {
        if (!request()->user()->role->canManage()) {
            abort(403);
        }

        return Inertia::render('partners/Create', [
            'templates' => PartnerTemplate::all(['id', 'name', 'description', 'policy_config']),
        ]);
    }

    public function store(StorePartnerRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // Resolve tenant info from Graph
        $tenantInfo = $this->tenantResolver->resolve($validated['tenant_id']);

        // Apply template if provided
        if (!empty($validated['template_id'])) {
            $template = PartnerTemplate::findOrFail($validated['template_id']);
            $validated = array_merge($template->policy_config, $validated);
        }

        // Build Graph API policy config
        $graphConfig = $this->buildGraphConfig($validated);

        // Create in Graph API
        $this->policyService->createPartner($validated['tenant_id'], $graphConfig);

        // Create local record
        $partner = PartnerOrganization::create([
            'tenant_id' => $validated['tenant_id'],
            'display_name' => $tenantInfo['displayName'] ?? $validated['tenant_id'],
            'domain' => $tenantInfo['defaultDomainName'] ?? null,
            'category' => $validated['category'],
            'notes' => $validated['notes'] ?? null,
            'b2b_inbound_enabled' => $validated['b2b_inbound_enabled'] ?? false,
            'b2b_outbound_enabled' => $validated['b2b_outbound_enabled'] ?? false,
            'mfa_trust_enabled' => $validated['mfa_trust_enabled'] ?? false,
            'device_trust_enabled' => $validated['device_trust_enabled'] ?? false,
            'direct_connect_enabled' => $validated['direct_connect_enabled'] ?? false,
            'last_synced_at' => now(),
        ]);

        $this->activityLog->log($request->user(), ActivityAction::PartnerCreated, $partner, [
            'tenant_id' => $partner->tenant_id,
        ]);

        return redirect()->route('partners.index')->with('success', "Partner '{$partner->display_name}' added.");
    }

    public function show(PartnerOrganization $partner): Response
    {
        $partner->load(['owner', 'guestUsers']);

        return Inertia::render('partners/Show', [
            'partner' => $partner,
            'activity' => $this->activityLog->forSubject($partner),
        ]);
    }

    public function update(UpdatePartnerRequest $request, PartnerOrganization $partner): RedirectResponse
    {
        $validated = $request->validated();

        // Build and apply Graph config for policy-related fields
        $graphConfig = $this->buildGraphConfig($validated);
        if (!empty($graphConfig)) {
            $this->policyService->updatePartner($partner->tenant_id, $graphConfig);
        }

        $partner->update($validated);

        $this->activityLog->log($request->user(), ActivityAction::PartnerUpdated, $partner, $validated);

        return redirect()->back()->with('success', 'Partner updated.');
    }

    public function destroy(PartnerOrganization $partner): RedirectResponse
    {
        if (!request()->user()->role->isAdmin()) {
            abort(403);
        }

        $this->policyService->deletePartner($partner->tenant_id);

        $this->activityLog->log(request()->user(), ActivityAction::PartnerDeleted, $partner, [
            'tenant_id' => $partner->tenant_id,
            'display_name' => $partner->display_name,
        ]);

        $partner->delete();

        return redirect()->route('partners.index')->with('success', 'Partner removed.');
    }

    private function buildGraphConfig(array $data): array
    {
        $config = [];

        if (isset($data['mfa_trust_enabled'])) {
            $config['inboundTrust'] = [
                'isMfaAccepted' => $data['mfa_trust_enabled'],
                'isCompliantDeviceAccepted' => $data['device_trust_enabled'] ?? false,
            ];
        }

        if (isset($data['b2b_inbound_enabled'])) {
            $accessType = $data['b2b_inbound_enabled'] ? 'allowed' : 'blocked';
            $config['b2bCollaborationInbound'] = [
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

        if (isset($data['b2b_outbound_enabled'])) {
            $accessType = $data['b2b_outbound_enabled'] ? 'allowed' : 'blocked';
            $config['b2bCollaborationOutbound'] = [
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

        return $config;
    }
}
```

**Step 4: Add routes to `routes/web.php`**

Add inside the `auth, verified` middleware group:

```php
Route::resource('partners', \App\Http\Controllers\PartnerOrganizationController::class)
    ->except(['edit']);
```

**Step 5: Run tests**

Run: `php artisan test tests/Feature/PartnerOrganizationTest.php`
Expected: All 7 tests PASS

**Step 6: Commit**

```bash
git add app/Http/Controllers/PartnerOrganizationController.php app/Http/Requests/StorePartnerRequest.php app/Http/Requests/UpdatePartnerRequest.php routes/web.php tests/Feature/PartnerOrganizationTest.php
git commit -m "feat: add PartnerOrganization controller with CRUD, RBAC, and Graph integration"
```

---

### Task 11: Create GuestUser Controller + Routes

**Files:**
- Create: `app/Http/Controllers/GuestUserController.php`
- Create: `app/Http/Requests/InviteGuestRequest.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/GuestUserControllerTest.php`

Follow the same pattern as Task 10. The controller should:
- `index()` — paginated list of local guest users with partner org filter
- `show(GuestUser $guest)` — detail view
- `create()` — invite form
- `store(InviteGuestRequest $request)` — call `GuestUserService::invite()`, create local record, log activity
- `destroy(GuestUser $guest)` — admin only, call `GuestUserService::deleteUser()`, delete local record

Routes:
```php
Route::resource('guests', \App\Http\Controllers\GuestUserController::class)
    ->except(['edit', 'update']);
```

**Commit:**
```bash
git commit -m "feat: add GuestUser controller with invitation and lifecycle management"
```

---

### Task 12: Create PartnerTemplate Controller + Routes

**Files:**
- Create: `app/Http/Controllers/PartnerTemplateController.php`
- Create: `app/Http/Requests/StoreTemplateRequest.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/PartnerTemplateControllerTest.php`

The controller should:
- `index()` — list templates
- `create()` — form to create template
- `store()` — admin only, create template
- `edit(PartnerTemplate $template)` — edit form
- `update()` — admin only, update template
- `destroy()` — admin only, delete template

Routes:
```php
Route::resource('templates', \App\Http\Controllers\PartnerTemplateController::class)
    ->middleware('role:admin');
```

**Commit:**
```bash
git commit -m "feat: add PartnerTemplate CRUD controller (admin-only)"
```

---

## Phase 4: Background Sync

### Task 13: Create Sync Commands

**Files:**
- Create: `app/Console/Commands/SyncPartners.php`
- Create: `app/Console/Commands/SyncGuests.php`
- Modify: `routes/console.php`
- Create: `tests/Feature/Commands/SyncPartnersTest.php`
- Create: `tests/Feature/Commands/SyncGuestsTest.php`

**Step 1: Write SyncPartners command**

Create `app/Console/Commands/SyncPartners.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\PartnerOrganization;
use App\Services\CrossTenantPolicyService;
use App\Services\TenantResolverService;
use Illuminate\Console\Command;

class SyncPartners extends Command
{
    protected $signature = 'sync:partners';
    protected $description = 'Sync partner organizations from Microsoft Graph API';

    public function handle(CrossTenantPolicyService $policyService, TenantResolverService $resolver): int
    {
        $this->info('Fetching partner configurations from Graph API...');

        $partners = $policyService->listPartners();
        $synced = 0;

        foreach ($partners as $partner) {
            $tenantId = $partner['tenantId'];

            // Try to resolve tenant display name
            $displayName = $tenantId;
            $domain = null;
            try {
                $info = $resolver->resolve($tenantId);
                $displayName = $info['displayName'] ?? $tenantId;
                $domain = $info['defaultDomainName'] ?? null;
            } catch (\Throwable $e) {
                $this->warn("Could not resolve tenant info for {$tenantId}: {$e->getMessage()}");
            }

            // Extract policy flags
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

        return Command::SUCCESS;
    }
}
```

**Step 2: Write SyncGuests command (same pattern)**

Create `app/Console/Commands/SyncGuests.php` — fetches guest users via `GuestUserService::listGuests()`, upserts `guest_users` table, attempts to match guest email domains to partner organizations.

**Step 3: Register schedule in `routes/console.php`**

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sync:partners')->everyFifteenMinutes();
Schedule::command('sync:guests')->everyFifteenMinutes();
```

**Step 4: Write tests, run, commit**

```bash
git commit -m "feat: add sync:partners and sync:guests scheduled commands"
```

---

## Phase 5: Vue Frontend Pages

### Task 14: Add Sidebar Navigation

**Files:**
- Modify: `resources/js/components/AppSidebar.vue`

Update `mainNavItems` array to add nav links:

```ts
import { Building2, LayoutGrid, Users, FileStack, Activity } from 'lucide-vue-next';
// Import route helpers as they are created

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Partners', href: '/partners', icon: Building2 },
    { title: 'Guests', href: '/guests', icon: Users },
    { title: 'Templates', href: '/templates', icon: FileStack },
    { title: 'Activity', href: '/activity', icon: Activity },
];
```

**Commit:**
```bash
git commit -m "feat: add sidebar navigation for partners, guests, templates, activity"
```

---

### Task 15: Create Partner Index Page

**Files:**
- Create: `resources/js/pages/partners/Index.vue`
- Create: `resources/js/types/partner.ts`

**Step 1: Create TypeScript types**

Create `resources/js/types/partner.ts`:

```ts
export type PartnerOrganization = {
    id: number;
    tenant_id: string;
    display_name: string;
    domain: string | null;
    category: 'vendor' | 'contractor' | 'strategic_partner' | 'customer' | 'other';
    owner_user_id: number | null;
    owner?: { id: number; name: string };
    notes: string | null;
    b2b_inbound_enabled: boolean;
    b2b_outbound_enabled: boolean;
    mfa_trust_enabled: boolean;
    device_trust_enabled: boolean;
    direct_connect_enabled: boolean;
    last_synced_at: string | null;
    created_at: string;
    guest_users_count?: number;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};
```

**Step 2: Create Index page**

Create `resources/js/pages/partners/Index.vue` with:
- Data table using shadcn-vue `Table` component
- Columns: Name, Domain, Category (badge), MFA Trust (green/red badge), B2B In/Out (badges), Owner, Last Synced
- Search input filtering by name/domain
- "Add Partner" button (visible to operators/admins)
- Pagination controls
- Use `AppLayout` wrapper with breadcrumbs

**Commit:**
```bash
git commit -m "feat: add Partners index page with data table"
```

---

### Task 16: Create Partner Detail Page

**Files:**
- Create: `resources/js/pages/partners/Show.vue`

Build the detail page with:
- Header: partner name, domain, category dropdown, owner select
- Policy toggles panel: MFA Trust, B2B Inbound, B2B Outbound, Device Trust, Direct Connect — each as a toggle card
- Guest users tab: simple table of guests from this partner
- Activity tab: timeline of actions
- Notes section: textarea with save
- Danger zone: delete button (admin only)

All policy toggle changes submit PATCH to the `partners.update` route.

**Commit:**
```bash
git commit -m "feat: add Partner detail page with policy toggles"
```

---

### Task 17: Create Partner Create/Wizard Page

**Files:**
- Create: `resources/js/pages/partners/Create.vue`

Build wizard with steps:
1. Tenant ID input → "Resolve" button → shows org name/domain via AJAX call to a resolve endpoint
2. Template selection (optional) or manual config toggles
3. Review summary
4. Submit

Add a route for tenant resolution:
```php
Route::post('partners/resolve-tenant', [PartnerOrganizationController::class, 'resolveTenant'])
    ->name('partners.resolve-tenant');
```

Add `resolveTenant()` method to controller that calls `TenantResolverService::resolve()`.

**Commit:**
```bash
git commit -m "feat: add Partner creation wizard with tenant resolution"
```

---

### Task 18: Create Guest Index + Invite Pages

**Files:**
- Create: `resources/js/pages/guests/Index.vue`
- Create: `resources/js/pages/guests/Show.vue`
- Create: `resources/js/pages/guests/Invite.vue`
- Create: `resources/js/types/guest.ts`

Follow the same patterns as partner pages. The invite page should have:
- Email address input
- Redirect URL input (with sensible default)
- Custom message textarea (optional)
- Send email toggle
- Submit button

**Commit:**
```bash
git commit -m "feat: add Guest user pages — list, detail, invite"
```

---

### Task 19: Create Dashboard Page (Replace Placeholder)

**Files:**
- Modify: `resources/js/pages/Dashboard.vue`
- Create: `app/Http/Controllers/DashboardController.php`
- Modify: `routes/web.php`

**Step 1: Create DashboardController**

```php
<?php

namespace App\Http\Controllers;

use App\Models\PartnerOrganization;
use App\Models\GuestUser;
use App\Enums\InvitationStatus;
use App\Services\ActivityLogService;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(ActivityLogService $activityLog)
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'total_partners' => PartnerOrganization::count(),
                'mfa_trust_enabled' => PartnerOrganization::where('mfa_trust_enabled', true)->count(),
                'mfa_trust_disabled' => PartnerOrganization::where('mfa_trust_enabled', false)->count(),
                'total_guests' => GuestUser::count(),
                'pending_invitations' => GuestUser::where('invitation_status', InvitationStatus::PendingAcceptance)->count(),
                'inactive_guests' => GuestUser::where('last_sign_in_at', '<', now()->subDays(90))
                    ->orWhereNull('last_sign_in_at')
                    ->count(),
                'partners_by_category' => PartnerOrganization::selectRaw('category, count(*) as count')
                    ->groupBy('category')
                    ->pluck('count', 'category'),
            ],
            'recentActivity' => $activityLog->recent(20),
        ]);
    }
}
```

**Step 2: Update route**

Change `Route::inertia('dashboard', 'Dashboard')` to:
```php
Route::get('dashboard', \App\Http\Controllers\DashboardController::class)->name('dashboard');
```

**Step 3: Update Dashboard.vue**

Replace placeholder content with summary cards (partner count, MFA trust %, guest stats, category breakdown) and recent activity feed.

**Commit:**
```bash
git commit -m "feat: add real dashboard with stats and activity feed"
```

---

### Task 20: Create Activity Log Page

**Files:**
- Create: `app/Http/Controllers/ActivityLogController.php`
- Create: `resources/js/pages/activity/Index.vue`
- Modify: `routes/web.php`

Simple paginated list of activity log entries with user name, action badge, subject, timestamp, and expandable details JSON.

**Commit:**
```bash
git commit -m "feat: add Activity log page"
```

---

### Task 21: Create Template Pages

**Files:**
- Create: `resources/js/pages/templates/Index.vue`
- Create: `resources/js/pages/templates/Create.vue`
- Create: `resources/js/pages/templates/Edit.vue`

CRUD pages for partner templates. The create/edit form should have:
- Name, description
- Policy config toggles (same as partner create, but saving as a reusable template)

**Commit:**
```bash
git commit -m "feat: add Template CRUD pages"
```

---

## Phase 6: Bulk Operations

### Task 22: Add CSV Import for Partners

**Files:**
- Create: `app/Http/Controllers/PartnerImportController.php`
- Create: `app/Http/Requests/ImportPartnersRequest.php`
- Create: `app/Jobs/ImportPartnerRow.php`
- Create: `resources/js/pages/partners/Import.vue`
- Modify: `routes/web.php`
- Create: `tests/Feature/PartnerImportTest.php`

The import controller accepts a CSV upload with columns: `tenant_id, category, mfa_trust, b2b_inbound, b2b_outbound`. Each row is dispatched as a queued job (`ImportPartnerRow`) that resolves the tenant, creates the Graph config, and creates the local record. Results are tracked and surfaced to the user.

**Commit:**
```bash
git commit -m "feat: add CSV bulk import for partner organizations"
```

---

### Task 23: Add CSV Bulk Invite for Guests

**Files:**
- Create: `app/Http/Controllers/GuestImportController.php`
- Create: `app/Jobs/InviteGuestRow.php`
- Modify: `routes/web.php`

Same pattern as partner import. CSV columns: `email, redirect_url, custom_message`.

**Commit:**
```bash
git commit -m "feat: add CSV bulk invite for guest users"
```

---

## Phase 7: Shared Inertia Data & Polish

### Task 24: Share User Role via Inertia

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`

In the `share()` method, add the user's role to shared data so Vue components can conditionally show/hide UI based on role:

```php
'auth' => [
    'user' => $request->user() ? [
        ...$request->user()->toArray(),
        'can_manage' => $request->user()->role->canManage(),
        'is_admin' => $request->user()->role->isAdmin(),
    ] : null,
],
```

**Commit:**
```bash
git commit -m "feat: share user role permissions via Inertia"
```

---

### Task 25: Add Partner Export (CSV)

**Files:**
- Create: `app/Http/Controllers/PartnerExportController.php`
- Modify: `routes/web.php`

Simple controller that streams a CSV download of all partner organizations with their policy settings.

**Commit:**
```bash
git commit -m "feat: add CSV export for partner organizations"
```

---

## Execution Summary

| Phase | Tasks | Description |
|-------|-------|-------------|
| 1 | 1-5 | Foundation: config, Graph service, DB, models, RBAC |
| 2 | 6-9 | Graph API service layer: policies, guests, resolver, audit |
| 3 | 10-12 | Backend controllers: partners, guests, templates |
| 4 | 13 | Background sync commands |
| 5 | 14-21 | Vue frontend pages |
| 6 | 22-23 | Bulk operations (CSV import/invite) |
| 7 | 24-25 | Polish: shared data, exports |

Total: 25 tasks. Tasks 1-13 are fully specified with code. Tasks 14-25 follow established patterns from earlier tasks and are described at the instruction level.

Each task is independently committable. Run `php artisan test` after each commit to verify nothing is broken.
