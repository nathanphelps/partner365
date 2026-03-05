<?php

use App\Enums\RemediationAction;
use App\Enums\ReviewDecision;
use App\Enums\ReviewInstanceStatus;
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
            'remediation_action' => 'remove',
            'reviewer_user_id' => $reviewer->id,
        ])
        ->assertRedirect(route('access-reviews.index'));

    expect(AccessReview::first()->remediation_action)->toBe(RemediationAction::FlagOnly);
});
