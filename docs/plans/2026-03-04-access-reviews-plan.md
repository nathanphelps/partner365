# Access Reviews Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add automated access reviews for guest users and partner organizations, with configurable remediation, recurring/one-time scheduling, and Microsoft Graph API integration.

**Architecture:** New service layer (`AccessReviewService`) wraps Graph API `identityGovernance/accessReviews` endpoints. Three new DB tables track review definitions, instances, and decisions locally. A sync command reconciles with Graph API on the same 15-minute schedule. Frontend uses 4 new Inertia pages with shadcn-vue components.

**Tech Stack:** Laravel 12, Pest PHP, Vue 3 + Inertia.js, shadcn-vue, Microsoft Graph API v1.0

---

### Task 1: Enums

**Files:**
- Create: `app/Enums/ReviewType.php`
- Create: `app/Enums/RecurrenceType.php`
- Create: `app/Enums/RemediationAction.php`
- Create: `app/Enums/ReviewInstanceStatus.php`
- Create: `app/Enums/ReviewDecision.php`
- Modify: `app/Enums/ActivityAction.php`

**Step 1: Create all five new enums**

```php
// app/Enums/ReviewType.php
<?php

namespace App\Enums;

enum ReviewType: string
{
    case GuestUsers = 'guest_users';
    case PartnerOrganizations = 'partner_organizations';
}
```

```php
// app/Enums/RecurrenceType.php
<?php

namespace App\Enums;

enum RecurrenceType: string
{
    case OneTime = 'one_time';
    case Recurring = 'recurring';
}
```

```php
// app/Enums/RemediationAction.php
<?php

namespace App\Enums;

enum RemediationAction: string
{
    case FlagOnly = 'flag_only';
    case Disable = 'disable';
    case Remove = 'remove';
}
```

```php
// app/Enums/ReviewInstanceStatus.php
<?php

namespace App\Enums;

enum ReviewInstanceStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Expired = 'expired';
}
```

```php
// app/Enums/ReviewDecision.php
<?php

namespace App\Enums;

enum ReviewDecision: string
{
    case Approve = 'approve';
    case Deny = 'deny';
    case Pending = 'pending';
}
```

**Step 2: Add new cases to ActivityAction enum**

Add these four cases to `app/Enums/ActivityAction.php`:

```php
    case AccessReviewCreated = 'access_review_created';
    case AccessReviewCompleted = 'access_review_completed';
    case AccessReviewDecisionMade = 'access_review_decision_made';
    case AccessReviewRemediationApplied = 'access_review_remediation_applied';
```

**Step 3: Commit**

```bash
git add app/Enums/ReviewType.php app/Enums/RecurrenceType.php app/Enums/RemediationAction.php app/Enums/ReviewInstanceStatus.php app/Enums/ReviewDecision.php app/Enums/ActivityAction.php
git commit -m "feat(access-reviews): add enums for review types, statuses, and decisions"
```

---

### Task 2: Database Migrations

**Files:**
- Create: `database/migrations/2026_03_04_400001_create_access_reviews_table.php`
- Create: `database/migrations/2026_03_04_400002_create_access_review_instances_table.php`
- Create: `database/migrations/2026_03_04_400003_create_access_review_decisions_table.php`

**Step 1: Create access_reviews migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('review_type'); // ReviewType enum
            $table->foreignId('scope_partner_id')->nullable()->constrained('partner_organizations')->nullOnDelete();
            $table->string('recurrence_type'); // RecurrenceType enum
            $table->unsignedInteger('recurrence_interval_days')->nullable();
            $table->string('remediation_action'); // RemediationAction enum
            $table->foreignId('reviewer_user_id')->constrained('users');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('graph_definition_id')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_reviews');
    }
};
```

**Step 2: Create access_review_instances migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_review_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_review_id')->constrained()->cascadeOnDelete();
            $table->string('status'); // ReviewInstanceStatus enum
            $table->timestamp('started_at');
            $table->timestamp('due_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_review_instances');
    }
};
```

**Step 3: Create access_review_decisions migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_review_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_review_instance_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type'); // 'guest_user' or 'partner_organization'
            $table->unsignedBigInteger('subject_id');
            $table->string('decision')->default('pending'); // ReviewDecision enum
            $table->text('justification')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->boolean('remediation_applied')->default(false);
            $table->timestamp('remediation_applied_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_review_decisions');
    }
};
```

**Step 4: Run migrations**

Run: `php artisan migrate`
Expected: All three tables created successfully.

**Step 5: Commit**

```bash
git add database/migrations/2026_03_04_400001_create_access_reviews_table.php database/migrations/2026_03_04_400002_create_access_review_instances_table.php database/migrations/2026_03_04_400003_create_access_review_decisions_table.php
git commit -m "feat(access-reviews): add database migrations for reviews, instances, and decisions"
```

---

### Task 3: Eloquent Models and Factories

**Files:**
- Create: `app/Models/AccessReview.php`
- Create: `app/Models/AccessReviewInstance.php`
- Create: `app/Models/AccessReviewDecision.php`
- Create: `database/factories/AccessReviewFactory.php`
- Create: `database/factories/AccessReviewInstanceFactory.php`
- Create: `database/factories/AccessReviewDecisionFactory.php`

**Step 1: Create AccessReview model**

```php
<?php

namespace App\Models;

use App\Enums\RecurrenceType;
use App\Enums\RemediationAction;
use App\Enums\ReviewType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'description', 'review_type', 'scope_partner_id',
        'recurrence_type', 'recurrence_interval_days', 'remediation_action',
        'reviewer_user_id', 'created_by_user_id', 'graph_definition_id',
        'next_review_at',
    ];

    protected function casts(): array
    {
        return [
            'review_type' => ReviewType::class,
            'recurrence_type' => RecurrenceType::class,
            'remediation_action' => RemediationAction::class,
            'next_review_at' => 'datetime',
        ];
    }

    public function scopePartner(): BelongsTo
    {
        return $this->belongsTo(PartnerOrganization::class, 'scope_partner_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(AccessReviewInstance::class);
    }

    public function latestInstance(): BelongsTo
    {
        return $this->belongsTo(AccessReviewInstance::class)
            ->ofMany('started_at', 'max');
    }
}
```

**Note on `latestInstance`:** Actually, `ofMany` requires `HasOne`. Change to:

```php
    public function latestInstance(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AccessReviewInstance::class)->latestOfMany('started_at');
    }
```

**Step 2: Create AccessReviewInstance model**

```php
<?php

namespace App\Models;

use App\Enums\ReviewInstanceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessReviewInstance extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_review_id', 'status', 'started_at', 'due_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReviewInstanceStatus::class,
            'started_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function accessReview(): BelongsTo
    {
        return $this->belongsTo(AccessReview::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AccessReviewDecision::class);
    }
}
```

**Step 3: Create AccessReviewDecision model**

```php
<?php

namespace App\Models;

use App\Enums\ReviewDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessReviewDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_review_instance_id', 'subject_type', 'subject_id',
        'decision', 'justification', 'decided_by_user_id', 'decided_at',
        'remediation_applied', 'remediation_applied_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => ReviewDecision::class,
            'decided_at' => 'datetime',
            'remediation_applied' => 'boolean',
            'remediation_applied_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(AccessReviewInstance::class, 'access_review_instance_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
```

**Step 4: Create AccessReviewFactory**

```php
<?php

namespace Database\Factories;

use App\Enums\RecurrenceType;
use App\Enums\RemediationAction;
use App\Enums\ReviewType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'review_type' => fake()->randomElement(ReviewType::cases()),
            'recurrence_type' => fake()->randomElement(RecurrenceType::cases()),
            'recurrence_interval_days' => fake()->randomElement([30, 60, 90, null]),
            'remediation_action' => fake()->randomElement(RemediationAction::cases()),
            'reviewer_user_id' => User::factory(),
            'created_by_user_id' => User::factory(),
            'next_review_at' => fake()->optional()->dateTimeBetween('now', '+90 days'),
        ];
    }
}
```

**Step 5: Create AccessReviewInstanceFactory**

```php
<?php

namespace Database\Factories;

use App\Enums\ReviewInstanceStatus;
use App\Models\AccessReview;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessReviewInstanceFactory extends Factory
{
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-30 days', 'now');
        $dueAt = fake()->dateTimeBetween($startedAt, '+30 days');

        return [
            'access_review_id' => AccessReview::factory(),
            'status' => fake()->randomElement(ReviewInstanceStatus::cases()),
            'started_at' => $startedAt,
            'due_at' => $dueAt,
            'completed_at' => null,
        ];
    }
}
```

**Step 6: Create AccessReviewDecisionFactory**

```php
<?php

namespace Database\Factories;

use App\Enums\ReviewDecision;
use App\Models\AccessReviewInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessReviewDecisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'access_review_instance_id' => AccessReviewInstance::factory(),
            'subject_type' => 'guest_user',
            'subject_id' => fake()->randomNumber(),
            'decision' => ReviewDecision::Pending,
            'justification' => null,
            'decided_by_user_id' => null,
            'decided_at' => null,
            'remediation_applied' => false,
            'remediation_applied_at' => null,
        ];
    }
}
```

**Step 7: Commit**

```bash
git add app/Models/AccessReview.php app/Models/AccessReviewInstance.php app/Models/AccessReviewDecision.php database/factories/AccessReviewFactory.php database/factories/AccessReviewInstanceFactory.php database/factories/AccessReviewDecisionFactory.php
git commit -m "feat(access-reviews): add Eloquent models and factories"
```

---

### Task 4: AccessReviewService

**Files:**
- Create: `app/Services/AccessReviewService.php`
- Test: `tests/Feature/AccessReviewServiceTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/AccessReviewServiceTest.php`:

```php
<?php

use App\Enums\RecurrenceType;
use App\Enums\RemediationAction;
use App\Enums\ReviewDecision;
use App\Enums\ReviewInstanceStatus;
use App\Enums\ReviewType;
use App\Models\AccessReview;
use App\Models\AccessReviewDecision;
use App\Models\AccessReviewInstance;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\AccessReviewService;
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

test('createDefinition creates review in Graph API and local DB', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/accessReviews/definitions' => Http::response([
            'id' => 'graph-def-123',
            'displayName' => 'Quarterly Guest Review',
        ], 201),
    ]);

    $admin = User::factory()->create(['role' => 'admin']);
    $reviewer = User::factory()->create(['role' => 'operator']);

    $service = app(AccessReviewService::class);
    $review = $service->createDefinition([
        'title' => 'Quarterly Guest Review',
        'review_type' => ReviewType::GuestUsers,
        'recurrence_type' => RecurrenceType::Recurring,
        'recurrence_interval_days' => 90,
        'remediation_action' => RemediationAction::Disable,
        'reviewer_user_id' => $reviewer->id,
        'created_by_user_id' => $admin->id,
    ]);

    expect($review)->toBeInstanceOf(AccessReview::class);
    expect($review->title)->toBe('Quarterly Guest Review');
    expect($review->graph_definition_id)->toBe('graph-def-123');
    expect(AccessReview::count())->toBe(1);
});

test('deleteDefinition removes from Graph API and local DB', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/accessReviews/definitions/*' => Http::response([], 204),
    ]);

    $review = AccessReview::factory()->create(['graph_definition_id' => 'graph-def-456']);

    $service = app(AccessReviewService::class);
    $service->deleteDefinition($review);

    expect(AccessReview::count())->toBe(0);
});

test('submitDecision updates decision record', function () {
    $operator = User::factory()->create(['role' => 'operator']);
    $instance = AccessReviewInstance::factory()->create(['status' => ReviewInstanceStatus::InProgress]);
    $decision = AccessReviewDecision::factory()->create([
        'access_review_instance_id' => $instance->id,
        'decision' => ReviewDecision::Pending,
    ]);

    $service = app(AccessReviewService::class);
    $service->submitDecision($decision, ReviewDecision::Approve, 'Still needed', $operator);

    $decision->refresh();
    expect($decision->decision)->toBe(ReviewDecision::Approve);
    expect($decision->justification)->toBe('Still needed');
    expect($decision->decided_by_user_id)->toBe($operator->id);
    expect($decision->decided_at)->not->toBeNull();
});

test('applyRemediations disables guest when remediation is disable', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/*' => Http::response([], 204),
    ]);

    $guest = GuestUser::factory()->create(['account_enabled' => true]);
    $review = AccessReview::factory()->create([
        'review_type' => ReviewType::GuestUsers,
        'remediation_action' => RemediationAction::Disable,
    ]);
    $instance = AccessReviewInstance::factory()->create([
        'access_review_id' => $review->id,
        'status' => ReviewInstanceStatus::Completed,
    ]);
    $decision = AccessReviewDecision::factory()->create([
        'access_review_instance_id' => $instance->id,
        'subject_type' => 'guest_user',
        'subject_id' => $guest->id,
        'decision' => ReviewDecision::Deny,
    ]);

    $service = app(AccessReviewService::class);
    $service->applyRemediations($instance);

    $decision->refresh();
    expect($decision->remediation_applied)->toBeTrue();
    expect($decision->remediation_applied_at)->not->toBeNull();
    expect($guest->fresh()->account_enabled)->toBeFalse();
});

test('applyRemediations removes guest when remediation is remove', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/*' => Http::response([], 204),
    ]);

    $guest = GuestUser::factory()->create();
    $review = AccessReview::factory()->create([
        'review_type' => ReviewType::GuestUsers,
        'remediation_action' => RemediationAction::Remove,
    ]);
    $instance = AccessReviewInstance::factory()->create([
        'access_review_id' => $review->id,
        'status' => ReviewInstanceStatus::Completed,
    ]);
    AccessReviewDecision::factory()->create([
        'access_review_instance_id' => $instance->id,
        'subject_type' => 'guest_user',
        'subject_id' => $guest->id,
        'decision' => ReviewDecision::Deny,
    ]);

    $service = app(AccessReviewService::class);
    $service->applyRemediations($instance);

    expect(GuestUser::find($guest->id))->toBeNull();
});

test('applyRemediations does not auto-remediate partner reviews', function () {
    $partner = PartnerOrganization::factory()->create();
    $review = AccessReview::factory()->create([
        'review_type' => ReviewType::PartnerOrganizations,
        'remediation_action' => RemediationAction::FlagOnly,
    ]);
    $instance = AccessReviewInstance::factory()->create([
        'access_review_id' => $review->id,
        'status' => ReviewInstanceStatus::Completed,
    ]);
    $decision = AccessReviewDecision::factory()->create([
        'access_review_instance_id' => $instance->id,
        'subject_type' => 'partner_organization',
        'subject_id' => $partner->id,
        'decision' => ReviewDecision::Deny,
    ]);

    $service = app(AccessReviewService::class);
    $service->applyRemediations($instance);

    $decision->refresh();
    expect($decision->remediation_applied)->toBeTrue();
    expect(PartnerOrganization::find($partner->id))->not->toBeNull();
});

test('applyRemediations skips approved decisions', function () {
    $guest = GuestUser::factory()->create(['account_enabled' => true]);
    $review = AccessReview::factory()->create([
        'review_type' => ReviewType::GuestUsers,
        'remediation_action' => RemediationAction::Disable,
    ]);
    $instance = AccessReviewInstance::factory()->create([
        'access_review_id' => $review->id,
        'status' => ReviewInstanceStatus::Completed,
    ]);
    AccessReviewDecision::factory()->create([
        'access_review_instance_id' => $instance->id,
        'subject_type' => 'guest_user',
        'subject_id' => $guest->id,
        'decision' => ReviewDecision::Approve,
    ]);

    $service = app(AccessReviewService::class);
    $service->applyRemediations($instance);

    expect($guest->fresh()->account_enabled)->toBeTrue();
});

test('createInstanceWithDecisions populates decisions for guest review', function () {
    $review = AccessReview::factory()->create([
        'review_type' => ReviewType::GuestUsers,
        'scope_partner_id' => null,
    ]);
    GuestUser::factory()->count(3)->create();

    $service = app(AccessReviewService::class);
    $instance = $service->createInstanceWithDecisions($review);

    expect($instance->decisions)->toHaveCount(3);
    expect($instance->decisions->first()->subject_type)->toBe('guest_user');
    expect($instance->decisions->first()->decision)->toBe(ReviewDecision::Pending);
});

test('createInstanceWithDecisions scopes to partner when set', function () {
    $partner = PartnerOrganization::factory()->create();
    $review = AccessReview::factory()->create([
        'review_type' => ReviewType::GuestUsers,
        'scope_partner_id' => $partner->id,
    ]);
    GuestUser::factory()->count(2)->create(['partner_organization_id' => $partner->id]);
    GuestUser::factory()->count(3)->create(); // other partner guests

    $service = app(AccessReviewService::class);
    $instance = $service->createInstanceWithDecisions($review);

    expect($instance->decisions)->toHaveCount(2);
});

test('createInstanceWithDecisions populates decisions for partner review', function () {
    $review = AccessReview::factory()->create([
        'review_type' => ReviewType::PartnerOrganizations,
    ]);
    PartnerOrganization::factory()->count(4)->create();

    $service = app(AccessReviewService::class);
    $instance = $service->createInstanceWithDecisions($review);

    expect($instance->decisions)->toHaveCount(4);
    expect($instance->decisions->first()->subject_type)->toBe('partner_organization');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AccessReviewServiceTest`
Expected: FAIL — `AccessReviewService` class not found.

**Step 3: Implement AccessReviewService**

```php
<?php

namespace App\Services;

use App\Enums\RemediationAction;
use App\Enums\ReviewDecision;
use App\Enums\ReviewInstanceStatus;
use App\Enums\ReviewType;
use App\Models\AccessReview;
use App\Models\AccessReviewDecision;
use App\Models\AccessReviewInstance;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\User;

class AccessReviewService
{
    public function __construct(
        private MicrosoftGraphService $graph,
        private GuestUserService $guestService,
    ) {}

    public function createDefinition(array $config): AccessReview
    {
        $graphResponse = $this->graph->post('/identityGovernance/accessReviews/definitions', [
            'displayName' => $config['title'],
            'scope' => [
                'query' => "/users?\$filter=userType eq 'Guest'",
                'queryType' => 'MicrosoftGraph',
            ],
        ]);

        $review = AccessReview::create([
            ...$config,
            'graph_definition_id' => $graphResponse['id'] ?? null,
        ]);

        return $review;
    }

    public function deleteDefinition(AccessReview $review): void
    {
        if ($review->graph_definition_id) {
            $this->graph->delete("/identityGovernance/accessReviews/definitions/{$review->graph_definition_id}");
        }

        $review->delete();
    }

    public function submitDecision(AccessReviewDecision $decision, ReviewDecision $verdict, ?string $justification, User $user): void
    {
        $decision->update([
            'decision' => $verdict,
            'justification' => $justification,
            'decided_by_user_id' => $user->id,
            'decided_at' => now(),
        ]);
    }

    public function applyRemediations(AccessReviewInstance $instance): void
    {
        $review = $instance->accessReview;
        $denyDecisions = $instance->decisions()
            ->where('decision', ReviewDecision::Deny)
            ->where('remediation_applied', false)
            ->get();

        foreach ($denyDecisions as $decision) {
            if ($review->review_type === ReviewType::GuestUsers && $review->remediation_action !== RemediationAction::FlagOnly) {
                $guest = GuestUser::find($decision->subject_id);
                if ($guest) {
                    match ($review->remediation_action) {
                        RemediationAction::Disable => $this->disableGuest($guest),
                        RemediationAction::Remove => $this->removeGuest($guest),
                        default => null,
                    };
                }
            }

            $decision->update([
                'remediation_applied' => true,
                'remediation_applied_at' => now(),
            ]);
        }
    }

    public function createInstanceWithDecisions(AccessReview $review): AccessReviewInstance
    {
        $instance = AccessReviewInstance::create([
            'access_review_id' => $review->id,
            'status' => ReviewInstanceStatus::Pending,
            'started_at' => now(),
            'due_at' => now()->addDays($review->recurrence_interval_days ?? 30),
        ]);

        $subjects = $this->getSubjectsForReview($review);

        foreach ($subjects as $subject) {
            AccessReviewDecision::create([
                'access_review_instance_id' => $instance->id,
                'subject_type' => $review->review_type === ReviewType::GuestUsers ? 'guest_user' : 'partner_organization',
                'subject_id' => $subject->id,
                'decision' => ReviewDecision::Pending,
            ]);
        }

        $instance->load('decisions');

        return $instance;
    }

    private function getSubjectsForReview(AccessReview $review): \Illuminate\Database\Eloquent\Collection
    {
        if ($review->review_type === ReviewType::PartnerOrganizations) {
            return PartnerOrganization::all();
        }

        $query = GuestUser::query();
        if ($review->scope_partner_id) {
            $query->where('partner_organization_id', $review->scope_partner_id);
        }

        return $query->get();
    }

    private function disableGuest(GuestUser $guest): void
    {
        $this->guestService->disableUser($guest->entra_user_id);
        $guest->update(['account_enabled' => false]);
    }

    private function removeGuest(GuestUser $guest): void
    {
        $this->guestService->deleteUser($guest->entra_user_id);
        $guest->delete();
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AccessReviewServiceTest`
Expected: All 9 tests PASS.

**Step 5: Commit**

```bash
git add app/Services/AccessReviewService.php tests/Feature/AccessReviewServiceTest.php
git commit -m "feat(access-reviews): add AccessReviewService with Graph API integration and remediation logic"
```

---

### Task 5: Form Request and Controller

**Files:**
- Create: `app/Http/Requests/StoreAccessReviewRequest.php`
- Create: `app/Http/Controllers/AccessReviewController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AccessReviewControllerTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/AccessReviewControllerTest.php`:

```php
<?php

use App\Enums\RecurrenceType;
use App\Enums\RemediationAction;
use App\Enums\ReviewDecision;
use App\Enums\ReviewInstanceStatus;
use App\Enums\ReviewType;
use App\Enums\UserRole;
use App\Models\AccessReview;
use App\Models\AccessReviewDecision;
use App\Models\AccessReviewInstance;
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

test('unauthenticated users cannot access access reviews', function () {
    $this->get(route('access-reviews.index'))->assertRedirect(route('login'));
});

test('all roles can view access reviews index', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($viewer)
        ->get(route('access-reviews.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('access-reviews/Index'));
});

test('only admins can access create form', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator]);

    $this->actingAs($operator)
        ->get(route('access-reviews.create'))
        ->assertForbidden();
});

test('admins can create access review', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/accessReviews/definitions' => Http::response([
            'id' => 'graph-def-789',
        ], 201),
    ]);

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $reviewer = User::factory()->create(['role' => UserRole::Operator]);

    $this->actingAs($admin)
        ->post(route('access-reviews.store'), [
            'title' => 'Q1 Guest Review',
            'review_type' => 'guest_users',
            'recurrence_type' => 'recurring',
            'recurrence_interval_days' => 90,
            'remediation_action' => 'disable',
            'reviewer_user_id' => $reviewer->id,
        ])
        ->assertRedirect(route('access-reviews.index'));

    expect(AccessReview::count())->toBe(1);
    expect(AccessReview::first()->title)->toBe('Q1 Guest Review');
});

test('operators cannot create access review', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $reviewer = User::factory()->create(['role' => UserRole::Operator]);

    $this->actingAs($operator)
        ->post(route('access-reviews.store'), [
            'title' => 'Sneaky Review',
            'review_type' => 'guest_users',
            'recurrence_type' => 'one_time',
            'remediation_action' => 'flag_only',
            'reviewer_user_id' => $reviewer->id,
        ])
        ->assertForbidden();
});

test('all roles can view access review show page', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $review = AccessReview::factory()->create();

    $this->actingAs($viewer)
        ->get(route('access-reviews.show', $review))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('access-reviews/Show'));
});

test('only admins can delete access review', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/accessReviews/definitions/*' => Http::response([], 204),
    ]);

    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $review = AccessReview::factory()->create(['graph_definition_id' => 'def-1']);

    $this->actingAs($operator)
        ->delete(route('access-reviews.destroy', $review))
        ->assertForbidden();

    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->delete(route('access-reviews.destroy', $review))
        ->assertRedirect(route('access-reviews.index'));

    expect(AccessReview::count())->toBe(0);
});

test('operators can submit decisions', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $decision = AccessReviewDecision::factory()->create([
        'decision' => ReviewDecision::Pending,
    ]);

    $this->actingAs($operator)
        ->post(route('access-reviews.decisions.submit', $decision), [
            'decision' => 'approve',
            'justification' => 'User is active',
        ])
        ->assertRedirect();

    expect($decision->fresh()->decision)->toBe(ReviewDecision::Approve);
});

test('viewers cannot submit decisions', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $decision = AccessReviewDecision::factory()->create();

    $this->actingAs($viewer)
        ->post(route('access-reviews.decisions.submit', $decision), [
            'decision' => 'approve',
        ])
        ->assertForbidden();
});

test('all roles can view instance detail', function () {
    $viewer = User::factory()->create(['role' => UserRole::Viewer]);
    $review = AccessReview::factory()->create();
    $instance = AccessReviewInstance::factory()->create(['access_review_id' => $review->id]);

    $this->actingAs($viewer)
        ->get(route('access-reviews.instances.show', [$review, $instance]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('access-reviews/Instance'));
});

test('partner review forces flag_only remediation', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/identityGovernance/accessReviews/definitions' => Http::response([
            'id' => 'graph-def-partner',
        ], 201),
    ]);

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $reviewer = User::factory()->create(['role' => UserRole::Operator]);

    $this->actingAs($admin)
        ->post(route('access-reviews.store'), [
            'title' => 'Partner Review',
            'review_type' => 'partner_organizations',
            'recurrence_type' => 'one_time',
            'remediation_action' => 'remove', // should be forced to flag_only
            'reviewer_user_id' => $reviewer->id,
        ])
        ->assertRedirect(route('access-reviews.index'));

    expect(AccessReview::first()->remediation_action)->toBe(RemediationAction::FlagOnly);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AccessReviewControllerTest`
Expected: FAIL — routes and controller not found.

**Step 3: Create StoreAccessReviewRequest**

```php
<?php

namespace App\Http\Requests;

use App\Enums\RecurrenceType;
use App\Enums\RemediationAction;
use App\Enums\ReviewType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'review_type' => ['required', Rule::enum(ReviewType::class)],
            'scope_partner_id' => ['nullable', 'exists:partner_organizations,id'],
            'recurrence_type' => ['required', Rule::enum(RecurrenceType::class)],
            'recurrence_interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'remediation_action' => ['required', Rule::enum(RemediationAction::class)],
            'reviewer_user_id' => ['required', 'exists:users,id'],
        ];
    }
}
```

**Step 4: Create AccessReviewController**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\RemediationAction;
use App\Enums\ReviewDecision;
use App\Enums\ReviewType;
use App\Http\Requests\StoreAccessReviewRequest;
use App\Models\AccessReview;
use App\Models\AccessReviewDecision;
use App\Models\AccessReviewInstance;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\AccessReviewService;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccessReviewController extends Controller
{
    public function __construct(
        private AccessReviewService $reviewService,
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): Response
    {
        $reviews = AccessReview::with(['reviewer', 'latestInstance', 'scopePartner'])
            ->withCount('instances')
            ->orderByDesc('created_at')
            ->paginate(25);

        return Inertia::render('access-reviews/Index', [
            'reviews' => $reviews,
            'canManage' => $request->user()->role->canManage(),
            'isAdmin' => $request->user()->role->isAdmin(),
        ]);
    }

    public function create(Request $request): Response
    {
        if (! $request->user()->role->isAdmin()) {
            abort(403);
        }

        return Inertia::render('access-reviews/Create', [
            'partners' => PartnerOrganization::orderBy('display_name')->get(['id', 'display_name']),
            'reviewers' => User::whereIn('role', ['admin', 'operator'])->orderBy('name')->get(['id', 'name', 'role']),
        ]);
    }

    public function store(StoreAccessReviewRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // Force flag_only for partner reviews
        if ($validated['review_type'] === ReviewType::PartnerOrganizations->value) {
            $validated['remediation_action'] = RemediationAction::FlagOnly->value;
        }

        $validated['created_by_user_id'] = $request->user()->id;

        $review = $this->reviewService->createDefinition($validated);

        $this->activityLog->log($request->user(), ActivityAction::AccessReviewCreated, $review, [
            'title' => $review->title,
        ]);

        return redirect()->route('access-reviews.index')->with('success', "Access review '{$review->title}' created.");
    }

    public function show(AccessReview $accessReview): Response
    {
        $accessReview->load(['reviewer', 'createdBy', 'scopePartner', 'instances' => function ($q) {
            $q->orderByDesc('started_at');
        }]);

        return Inertia::render('access-reviews/Show', [
            'review' => $accessReview,
        ]);
    }

    public function destroy(Request $request, AccessReview $accessReview): RedirectResponse
    {
        if (! $request->user()->role->isAdmin()) {
            abort(403);
        }

        $this->reviewService->deleteDefinition($accessReview);

        return redirect()->route('access-reviews.index')->with('success', 'Access review deleted.');
    }

    public function showInstance(AccessReview $accessReview, AccessReviewInstance $instance): Response
    {
        $instance->load(['decisions.decidedBy']);
        $instance->loadCount([
            'decisions',
            'decisions as approved_count' => fn ($q) => $q->where('decision', ReviewDecision::Approve),
            'decisions as denied_count' => fn ($q) => $q->where('decision', ReviewDecision::Deny),
            'decisions as pending_count' => fn ($q) => $q->where('decision', ReviewDecision::Pending),
        ]);

        return Inertia::render('access-reviews/Instance', [
            'review' => $accessReview,
            'instance' => $instance,
            'canManage' => request()->user()->role->canManage(),
            'isAdmin' => request()->user()->role->isAdmin(),
        ]);
    }

    public function submitDecision(Request $request, AccessReviewDecision $decision): RedirectResponse
    {
        if (! $request->user()->role->canManage()) {
            abort(403);
        }

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(ReviewDecision::class)],
            'justification' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->reviewService->submitDecision(
            $decision,
            ReviewDecision::from($validated['decision']),
            $validated['justification'] ?? null,
            $request->user(),
        );

        $this->activityLog->log($request->user(), ActivityAction::AccessReviewDecisionMade, $decision, [
            'decision' => $validated['decision'],
        ]);

        return redirect()->back()->with('success', 'Decision recorded.');
    }

    public function applyRemediations(Request $request, AccessReviewInstance $instance): RedirectResponse
    {
        if (! $request->user()->role->isAdmin()) {
            abort(403);
        }

        $this->reviewService->applyRemediations($instance);

        $this->activityLog->log($request->user(), ActivityAction::AccessReviewRemediationApplied, $instance, [
            'review_title' => $instance->accessReview->title,
        ]);

        return redirect()->back()->with('success', 'Remediations applied.');
    }
}
```

**Step 5: Add routes to `routes/web.php`**

Add inside the existing `middleware(['auth', 'verified', 'approved'])` group, after the activity route:

```php
    Route::resource('access-reviews', \App\Http\Controllers\AccessReviewController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::get('access-reviews/{access_review}/instances/{instance}', [\App\Http\Controllers\AccessReviewController::class, 'showInstance'])->name('access-reviews.instances.show');
    Route::post('access-reviews/decisions/{decision}', [\App\Http\Controllers\AccessReviewController::class, 'submitDecision'])->name('access-reviews.decisions.submit');
    Route::post('access-reviews/instances/{instance}/apply', [\App\Http\Controllers\AccessReviewController::class, 'applyRemediations'])->name('access-reviews.instances.apply');
```

**Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=AccessReviewControllerTest`
Expected: All 10 tests PASS.

**Step 7: Commit**

```bash
git add app/Http/Requests/StoreAccessReviewRequest.php app/Http/Controllers/AccessReviewController.php routes/web.php tests/Feature/AccessReviewControllerTest.php
git commit -m "feat(access-reviews): add controller, form request, routes, and RBAC tests"
```

---

### Task 6: Sync Command

**Files:**
- Create: `app/Console/Commands/SyncAccessReviews.php`
- Test: `tests/Feature/SyncAccessReviewsTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/SyncAccessReviewsTest.php`:

```php
<?php

use App\Enums\RecurrenceType;
use App\Enums\ReviewInstanceStatus;
use App\Models\AccessReview;
use App\Models\AccessReviewInstance;
use App\Models\GuestUser;
use App\Models\SyncLog;
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

test('sync command creates new instances for overdue recurring reviews', function () {
    $review = AccessReview::factory()->create([
        'recurrence_type' => RecurrenceType::Recurring,
        'recurrence_interval_days' => 30,
        'next_review_at' => now()->subDay(),
    ]);
    GuestUser::factory()->count(2)->create();

    $this->artisan('sync:access-reviews')
        ->assertSuccessful();

    expect(AccessReviewInstance::where('access_review_id', $review->id)->count())->toBe(1);
    $review->refresh();
    expect($review->next_review_at->isFuture())->toBeTrue();
});

test('sync command does not create instance for future reviews', function () {
    AccessReview::factory()->create([
        'recurrence_type' => RecurrenceType::Recurring,
        'recurrence_interval_days' => 30,
        'next_review_at' => now()->addDays(15),
    ]);

    $this->artisan('sync:access-reviews')
        ->assertSuccessful();

    expect(AccessReviewInstance::count())->toBe(0);
});

test('sync command expires overdue instances', function () {
    $instance = AccessReviewInstance::factory()->create([
        'status' => ReviewInstanceStatus::InProgress,
        'due_at' => now()->subDay(),
    ]);

    $this->artisan('sync:access-reviews')
        ->assertSuccessful();

    expect($instance->fresh()->status)->toBe(ReviewInstanceStatus::Expired);
});

test('sync command logs to sync_logs', function () {
    $this->artisan('sync:access-reviews')
        ->assertSuccessful();

    expect(SyncLog::where('type', 'access_reviews')->count())->toBe(1);
    expect(SyncLog::where('type', 'access_reviews')->first()->status)->toBe('completed');
});

test('sync command handles errors gracefully', function () {
    // Create a review with invalid state that will trigger an error
    AccessReview::factory()->create([
        'recurrence_type' => RecurrenceType::Recurring,
        'recurrence_interval_days' => null, // will cause issue in addDays
        'next_review_at' => now()->subDay(),
    ]);

    $this->artisan('sync:access-reviews')
        ->assertFailed();

    expect(SyncLog::where('type', 'access_reviews')->first()->status)->toBe('failed');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SyncAccessReviewsTest`
Expected: FAIL — command not found.

**Step 3: Implement SyncAccessReviews command**

```php
<?php

namespace App\Console\Commands;

use App\Enums\RecurrenceType;
use App\Enums\ReviewInstanceStatus;
use App\Models\AccessReview;
use App\Models\AccessReviewInstance;
use App\Models\SyncLog;
use App\Services\AccessReviewService;
use Illuminate\Console\Command;

class SyncAccessReviews extends Command
{
    protected $signature = 'sync:access-reviews';

    protected $description = 'Sync access review instances and expire overdue reviews';

    public function handle(AccessReviewService $reviewService): int
    {
        $log = SyncLog::create([
            'type' => 'access_reviews',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $synced = 0;

            // Expire overdue instances
            $expired = AccessReviewInstance::whereIn('status', [
                ReviewInstanceStatus::Pending,
                ReviewInstanceStatus::InProgress,
            ])->where('due_at', '<', now())->get();

            foreach ($expired as $instance) {
                $instance->update(['status' => ReviewInstanceStatus::Expired]);
                $synced++;
            }

            $this->info("Expired {$expired->count()} overdue instances.");

            // Create new instances for overdue recurring reviews
            $dueReviews = AccessReview::where('recurrence_type', RecurrenceType::Recurring)
                ->whereNotNull('next_review_at')
                ->where('next_review_at', '<=', now())
                ->get();

            foreach ($dueReviews as $review) {
                $reviewService->createInstanceWithDecisions($review);
                $review->update([
                    'next_review_at' => now()->addDays($review->recurrence_interval_days),
                ]);
                $synced++;
            }

            $this->info("Created {$dueReviews->count()} new review instances.");

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

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=SyncAccessReviewsTest`
Expected: All 5 tests PASS.

**Step 5: Commit**

```bash
git add app/Console/Commands/SyncAccessReviews.php tests/Feature/SyncAccessReviewsTest.php
git commit -m "feat(access-reviews): add sync:access-reviews command for recurring instances and expiry"
```

---

### Task 7: TypeScript Types

**Files:**
- Create: `resources/js/types/access-review.ts`

**Step 1: Create the types file**

```typescript
export type AccessReview = {
    id: number;
    title: string;
    description: string | null;
    review_type: 'guest_users' | 'partner_organizations';
    scope_partner_id: number | null;
    scope_partner?: { id: number; display_name: string };
    recurrence_type: 'one_time' | 'recurring';
    recurrence_interval_days: number | null;
    remediation_action: 'flag_only' | 'disable' | 'remove';
    reviewer_user_id: number;
    reviewer?: { id: number; name: string };
    created_by_user_id: number;
    created_by?: { id: number; name: string };
    graph_definition_id: string | null;
    next_review_at: string | null;
    instances_count?: number;
    latest_instance?: AccessReviewInstance;
    instances?: AccessReviewInstance[];
    created_at: string;
};

export type AccessReviewInstance = {
    id: number;
    access_review_id: number;
    status: 'pending' | 'in_progress' | 'completed' | 'expired';
    started_at: string;
    due_at: string;
    completed_at: string | null;
    decisions?: AccessReviewDecision[];
    decisions_count?: number;
    approved_count?: number;
    denied_count?: number;
    pending_count?: number;
};

export type AccessReviewDecision = {
    id: number;
    access_review_instance_id: number;
    subject_type: 'guest_user' | 'partner_organization';
    subject_id: number;
    decision: 'approve' | 'deny' | 'pending';
    justification: string | null;
    decided_by_user_id: number | null;
    decided_by?: { id: number; name: string };
    decided_at: string | null;
    remediation_applied: boolean;
    remediation_applied_at: string | null;
};
```

**Step 2: Commit**

```bash
git add resources/js/types/access-review.ts
git commit -m "feat(access-reviews): add TypeScript types for access reviews"
```

---

### Task 8: Frontend — Index Page

**Files:**
- Create: `resources/js/pages/access-reviews/Index.vue`

**Step 1: Create the Index page**

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
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
import type { AccessReview } from '@/types/access-review';
import type { Paginated } from '@/types/partner';

defineProps<{
    reviews: Paginated<AccessReview>;
    canManage: boolean;
    isAdmin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Access Reviews', href: '/access-reviews' },
];

function formatDate(val: string | null): string {
    if (!val) return '\u2014';
    return new Date(val).toLocaleDateString();
}

function reviewTypeLabel(type: string): string {
    return type === 'guest_users' ? 'Guest Users' : 'Partner Organizations';
}

function statusVariant(status: string): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<string, 'default' | 'destructive' | 'outline' | 'secondary'> = {
        pending: 'outline',
        in_progress: 'secondary',
        completed: 'default',
        expired: 'destructive',
    };
    return map[status] ?? 'outline';
}

function statusLabel(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function compliancePercent(instance: AccessReview['latest_instance']): string {
    if (!instance || !instance.decisions_count) return '\u2014';
    const decided = (instance.approved_count ?? 0) + (instance.denied_count ?? 0);
    return Math.round((decided / instance.decisions_count) * 100) + '%';
}
</script>

<template>
    <Head title="Access Reviews" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Access Reviews</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Periodic certification of guest user and partner organization access.
                    </p>
                </div>
                <Link v-if="isAdmin" href="/access-reviews/create">
                    <Button>Create Review</Button>
                </Link>
            </div>

            <Card v-if="reviews.data.length === 0">
                <CardContent class="py-12 text-center text-muted-foreground">
                    No access reviews configured yet.
                </CardContent>
            </Card>

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Title</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Recurrence</TableHead>
                        <TableHead>Reviewer</TableHead>
                        <TableHead>Latest Status</TableHead>
                        <TableHead>Compliance</TableHead>
                        <TableHead>Created</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="review in reviews.data" :key="review.id">
                        <TableCell>
                            <Link :href="`/access-reviews/${review.id}`" class="font-medium hover:underline">
                                {{ review.title }}
                            </Link>
                        </TableCell>
                        <TableCell>{{ reviewTypeLabel(review.review_type) }}</TableCell>
                        <TableCell>
                            <span v-if="review.recurrence_type === 'recurring'">
                                Every {{ review.recurrence_interval_days }}d
                            </span>
                            <span v-else>One-time</span>
                        </TableCell>
                        <TableCell>{{ review.reviewer?.name ?? '\u2014' }}</TableCell>
                        <TableCell>
                            <Badge v-if="review.latest_instance" :variant="statusVariant(review.latest_instance.status)">
                                {{ statusLabel(review.latest_instance.status) }}
                            </Badge>
                            <span v-else class="text-muted-foreground">\u2014</span>
                        </TableCell>
                        <TableCell>{{ compliancePercent(review.latest_instance) }}</TableCell>
                        <TableCell>{{ formatDate(review.created_at) }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/access-reviews/Index.vue
git commit -m "feat(access-reviews): add Index page with review listing and compliance stats"
```

---

### Task 9: Frontend — Create Page

**Files:**
- Create: `resources/js/pages/access-reviews/Create.vue`

**Step 1: Create the Create page**

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    partners: { id: number; display_name: string }[];
    reviewers: { id: number; name: string; role: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Access Reviews', href: '/access-reviews' },
    { title: 'Create', href: '/access-reviews/create' },
];

const form = useForm({
    title: '',
    description: '',
    review_type: 'guest_users',
    scope_partner_id: null as number | null,
    recurrence_type: 'one_time',
    recurrence_interval_days: 90,
    remediation_action: 'flag_only',
    reviewer_user_id: null as number | null,
});

const isPartnerReview = computed(() => form.review_type === 'partner_organizations');

function submit() {
    form.post('/access-reviews');
}
</script>

<template>
    <Head title="Create Access Review" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto max-w-2xl p-6">
            <h1 class="mb-6 text-2xl font-semibold">Create Access Review</h1>

            <form @submit.prevent="submit" class="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Review Details</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <Label for="title">Title</Label>
                            <Input id="title" v-model="form.title" placeholder="e.g. Q1 Guest Access Review" />
                            <p v-if="form.errors.title" class="mt-1 text-sm text-destructive">{{ form.errors.title }}</p>
                        </div>

                        <div>
                            <Label for="description">Description</Label>
                            <Textarea id="description" v-model="form.description" placeholder="Optional description..." />
                        </div>

                        <div>
                            <Label>Review Type</Label>
                            <Select v-model="form.review_type">
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="guest_users">Guest Users</SelectItem>
                                    <SelectItem value="partner_organizations">Partner Organizations</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div v-if="form.review_type === 'guest_users'">
                            <Label>Scope to Partner (optional)</Label>
                            <Select v-model="form.scope_partner_id">
                                <SelectTrigger><SelectValue placeholder="All guests" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="null">All guests</SelectItem>
                                    <SelectItem v-for="p in partners" :key="p.id" :value="p.id">
                                        {{ p.display_name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Schedule</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <Label>Recurrence</Label>
                            <Select v-model="form.recurrence_type">
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="one_time">One-time</SelectItem>
                                    <SelectItem value="recurring">Recurring</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div v-if="form.recurrence_type === 'recurring'">
                            <Label for="interval">Interval (days)</Label>
                            <Input id="interval" type="number" v-model.number="form.recurrence_interval_days" min="1" max="365" />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Remediation & Reviewer</CardTitle>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div>
                            <Label>Remediation Action</Label>
                            <Select v-model="form.remediation_action" :disabled="isPartnerReview">
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="flag_only">Flag Only</SelectItem>
                                    <SelectItem value="disable" :disabled="isPartnerReview">Disable Account</SelectItem>
                                    <SelectItem value="remove" :disabled="isPartnerReview">Remove User</SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="isPartnerReview" class="mt-1 text-sm text-muted-foreground">
                                Partner reviews are always flag-only due to high impact.
                            </p>
                        </div>

                        <div>
                            <Label>Reviewer</Label>
                            <Select v-model="form.reviewer_user_id">
                                <SelectTrigger><SelectValue placeholder="Select a reviewer" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="r in reviewers" :key="r.id" :value="r.id">
                                        {{ r.name }} ({{ r.role }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="form.errors.reviewer_user_id" class="mt-1 text-sm text-destructive">{{ form.errors.reviewer_user_id }}</p>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end gap-3">
                    <Button type="button" variant="outline" @click="$inertia.visit('/access-reviews')">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Creating...' : 'Create Review' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/access-reviews/Create.vue
git commit -m "feat(access-reviews): add Create page with form for defining new reviews"
```

---

### Task 10: Frontend — Show Page

**Files:**
- Create: `resources/js/pages/access-reviews/Show.vue`

**Step 1: Create the Show page**

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
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
import type { AccessReview } from '@/types/access-review';

const props = defineProps<{
    review: AccessReview;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Access Reviews', href: '/access-reviews' },
    { title: props.review.title, href: `/access-reviews/${props.review.id}` },
];

function formatDate(val: string | null): string {
    if (!val) return '\u2014';
    return new Date(val).toLocaleDateString();
}

function statusVariant(status: string): 'default' | 'destructive' | 'outline' | 'secondary' {
    const map: Record<string, 'default' | 'destructive' | 'outline' | 'secondary'> = {
        pending: 'outline',
        in_progress: 'secondary',
        completed: 'default',
        expired: 'destructive',
    };
    return map[status] ?? 'outline';
}

function statusLabel(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const showDeleteConfirm = ref(false);
const deleting = ref(false);

function deleteReview() {
    deleting.value = true;
    router.delete(`/access-reviews/${props.review.id}`, {
        onFinish: () => { deleting.value = false; },
    });
}
</script>

<template>
    <Head :title="review.title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div class="flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">{{ review.title }}</h1>
                    <p v-if="review.description" class="mt-1 text-sm text-muted-foreground">{{ review.description }}</p>
                </div>
            </div>

            <Separator />

            <Card>
                <CardHeader>
                    <CardTitle>Configuration</CardTitle>
                </CardHeader>
                <CardContent class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <span class="text-muted-foreground">Type</span>
                    <span>{{ review.review_type === 'guest_users' ? 'Guest Users' : 'Partner Organizations' }}</span>

                    <span class="text-muted-foreground">Scope</span>
                    <span>{{ review.scope_partner?.display_name ?? 'All' }}</span>

                    <span class="text-muted-foreground">Recurrence</span>
                    <span>
                        <template v-if="review.recurrence_type === 'recurring'">Every {{ review.recurrence_interval_days }} days</template>
                        <template v-else>One-time</template>
                    </span>

                    <span class="text-muted-foreground">Remediation</span>
                    <span>{{ statusLabel(review.remediation_action) }}</span>

                    <span class="text-muted-foreground">Reviewer</span>
                    <span>{{ review.reviewer?.name ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Created By</span>
                    <span>{{ review.created_by?.name ?? '\u2014' }}</span>

                    <span class="text-muted-foreground">Next Review</span>
                    <span>{{ formatDate(review.next_review_at) }}</span>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Review Instances</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table v-if="review.instances?.length">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Started</TableHead>
                                <TableHead>Due</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Completed</TableHead>
                                <TableHead></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="inst in review.instances" :key="inst.id">
                                <TableCell>{{ formatDate(inst.started_at) }}</TableCell>
                                <TableCell>{{ formatDate(inst.due_at) }}</TableCell>
                                <TableCell>
                                    <Badge :variant="statusVariant(inst.status)">{{ statusLabel(inst.status) }}</Badge>
                                </TableCell>
                                <TableCell>{{ formatDate(inst.completed_at) }}</TableCell>
                                <TableCell>
                                    <Link :href="`/access-reviews/${review.id}/instances/${inst.id}`">
                                        <Button variant="outline" size="sm">View Decisions</Button>
                                    </Link>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <p v-else class="py-6 text-center text-sm text-muted-foreground">No instances yet.</p>
                </CardContent>
            </Card>

            <!-- Danger Zone -->
            <Card class="border-destructive/50">
                <CardHeader>
                    <CardTitle class="text-destructive">Danger Zone</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="!showDeleteConfirm">
                        <p class="mb-3 text-sm text-muted-foreground">Delete this access review and all its instances and decisions.</p>
                        <Button variant="destructive" @click="showDeleteConfirm = true">Delete Review</Button>
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <p class="text-sm font-medium">Are you sure? This cannot be undone.</p>
                        <div class="flex gap-2">
                            <Button variant="destructive" @click="deleteReview" :disabled="deleting">
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
git add resources/js/pages/access-reviews/Show.vue
git commit -m "feat(access-reviews): add Show page with config summary and instance listing"
```

---

### Task 11: Frontend — Instance Page

**Files:**
- Create: `resources/js/pages/access-reviews/Instance.vue`

**Step 1: Create the Instance page**

```vue
<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import type { AccessReview, AccessReviewInstance, AccessReviewDecision } from '@/types/access-review';

const props = defineProps<{
    review: AccessReview;
    instance: AccessReviewInstance;
    canManage: boolean;
    isAdmin: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Access Reviews', href: '/access-reviews' },
    { title: props.review.title, href: `/access-reviews/${props.review.id}` },
    { title: 'Instance', href: '#' },
];

function formatDate(val: string | null): string {
    if (!val) return '\u2014';
    return new Date(val).toLocaleDateString();
}

function decisionVariant(decision: string): 'default' | 'destructive' | 'outline' {
    if (decision === 'approve') return 'default';
    if (decision === 'deny') return 'destructive';
    return 'outline';
}

function decisionLabel(decision: string): string {
    return decision.replace(/\b\w/g, (c) => c.toUpperCase());
}

const editingId = ref<number | null>(null);
const decisionForm = useForm({
    decision: '' as string,
    justification: '',
});

function startEdit(d: AccessReviewDecision) {
    editingId.value = d.id;
    decisionForm.decision = d.decision;
    decisionForm.justification = d.justification ?? '';
}

function cancelEdit() {
    editingId.value = null;
    decisionForm.reset();
}

function submitDecision(decisionId: number) {
    decisionForm.post(`/access-reviews/decisions/${decisionId}`, {
        onSuccess: () => { editingId.value = null; },
    });
}

const applying = ref(false);

function applyRemediations() {
    applying.value = true;
    router.post(`/access-reviews/instances/${props.instance.id}/apply`, {}, {
        onFinish: () => { applying.value = false; },
    });
}
</script>

<template>
    <Head :title="`${review.title} - Instance`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <div>
                <h1 class="text-2xl font-semibold">{{ review.title }}</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ formatDate(instance.started_at) }} &mdash; {{ formatDate(instance.due_at) }}
                </p>
            </div>

            <div class="flex flex-wrap gap-4">
                <Card class="flex-1">
                    <CardContent class="pt-6 text-center">
                        <div class="text-2xl font-bold">{{ instance.approved_count ?? 0 }}</div>
                        <div class="text-sm text-muted-foreground">Approved</div>
                    </CardContent>
                </Card>
                <Card class="flex-1">
                    <CardContent class="pt-6 text-center">
                        <div class="text-2xl font-bold text-destructive">{{ instance.denied_count ?? 0 }}</div>
                        <div class="text-sm text-muted-foreground">Denied</div>
                    </CardContent>
                </Card>
                <Card class="flex-1">
                    <CardContent class="pt-6 text-center">
                        <div class="text-2xl font-bold">{{ instance.pending_count ?? 0 }}</div>
                        <div class="text-sm text-muted-foreground">Pending</div>
                    </CardContent>
                </Card>
            </div>

            <Separator />

            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Decisions</h2>
                <Button v-if="isAdmin && (instance.denied_count ?? 0) > 0" variant="destructive" @click="applyRemediations" :disabled="applying">
                    {{ applying ? 'Applying\u2026' : 'Apply Remediations' }}
                </Button>
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Subject</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Decision</TableHead>
                        <TableHead>Justification</TableHead>
                        <TableHead>Decided By</TableHead>
                        <TableHead v-if="canManage"></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="d in instance.decisions" :key="d.id">
                        <TableCell class="font-mono text-xs">#{{ d.subject_id }}</TableCell>
                        <TableCell>{{ d.subject_type === 'guest_user' ? 'Guest' : 'Partner' }}</TableCell>
                        <TableCell>
                            <template v-if="editingId === d.id">
                                <Select v-model="decisionForm.decision">
                                    <SelectTrigger class="w-32"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="approve">Approve</SelectItem>
                                        <SelectItem value="deny">Deny</SelectItem>
                                    </SelectContent>
                                </Select>
                            </template>
                            <Badge v-else :variant="decisionVariant(d.decision)">{{ decisionLabel(d.decision) }}</Badge>
                        </TableCell>
                        <TableCell>
                            <template v-if="editingId === d.id">
                                <Textarea v-model="decisionForm.justification" placeholder="Justification..." class="w-48" />
                            </template>
                            <template v-else>{{ d.justification ?? '\u2014' }}</template>
                        </TableCell>
                        <TableCell>{{ d.decided_by?.name ?? '\u2014' }}</TableCell>
                        <TableCell v-if="canManage">
                            <template v-if="editingId === d.id">
                                <div class="flex gap-1">
                                    <Button size="sm" @click="submitDecision(d.id)" :disabled="decisionForm.processing">Save</Button>
                                    <Button size="sm" variant="outline" @click="cancelEdit">Cancel</Button>
                                </div>
                            </template>
                            <Button v-else-if="d.decision === 'pending'" size="sm" variant="outline" @click="startEdit(d)">
                                Review
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </AppLayout>
</template>
```

**Step 2: Commit**

```bash
git add resources/js/pages/access-reviews/Instance.vue
git commit -m "feat(access-reviews): add Instance page with inline decision editing and remediation trigger"
```

---

### Task 12: Navigation and Dashboard Integration

**Files:**
- Modify: `resources/js/components/AppSidebar.vue` — add "Access Reviews" nav item
- Modify: `app/Http/Controllers/DashboardController.php` — add access review stats

**Step 1: Add nav item to AppSidebar.vue**

Find the `mainNavItems` computed property. Add this entry after the "Activity" item (or wherever appropriate):

```typescript
{
    title: 'Access Reviews',
    href: '/access-reviews',
    icon: ClipboardCheck, // from lucide-vue-next
},
```

Add `ClipboardCheck` to the lucide-vue-next import.

**Step 2: Add access review stats to DashboardController**

Add to the `stats` array in `DashboardController::__invoke()`:

```php
'active_reviews' => \App\Models\AccessReview::count(),
'overdue_reviews' => \App\Models\AccessReviewInstance::whereIn('status', [
    \App\Enums\ReviewInstanceStatus::Pending,
    \App\Enums\ReviewInstanceStatus::InProgress,
])->where('due_at', '<', now())->count(),
```

**Step 3: Run the full test suite**

Run: `composer run test`
Expected: All tests pass (existing + new).

**Step 4: Commit**

```bash
git add resources/js/components/AppSidebar.vue app/Http/Controllers/DashboardController.php
git commit -m "feat(access-reviews): add navigation item and dashboard stats"
```

---

### Task 13: Generate Wayfinder Routes

After adding routes, regenerate the Wayfinder TypeScript route helpers so the frontend can use typed route functions.

**Step 1: Generate routes**

Run: `php artisan wayfinder:generate`

**Step 2: Verify the generated files include access-reviews routes**

Check: `resources/js/routes/access-reviews/index.ts` exists and contains route helpers.

**Step 3: Update frontend pages to use generated route helpers instead of hardcoded strings**

Replace hardcoded `/access-reviews` strings in the Vue pages with the generated route helpers where appropriate. This is optional cleanup — the hardcoded paths work fine.

**Step 4: Commit**

```bash
git add resources/js/routes/
git commit -m "chore: regenerate Wayfinder route helpers for access reviews"
```

---

### Task 14: Final Integration Test and Lint

**Step 1: Run full CI check**

Run: `composer run ci:check`

This runs: lint check, format check, type check, and all Pest tests.

Expected: All checks pass.

**Step 2: Fix any lint or type issues found**

If Pint or ESLint report issues, fix them.

**Step 3: Run type check specifically**

Run: `npm run types:check`
Expected: No TypeScript errors.

**Step 4: Final commit if any fixes were needed**

```bash
git add -A
git commit -m "chore: fix lint and type issues for access reviews feature"
```
