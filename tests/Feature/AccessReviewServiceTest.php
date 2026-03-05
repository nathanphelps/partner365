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
    GuestUser::factory()->count(3)->create();

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
