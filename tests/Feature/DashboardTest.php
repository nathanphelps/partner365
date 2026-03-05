<?php

use App\Enums\AssignmentStatus;
use App\Enums\InvitationStatus;
use App\Enums\ReviewInstanceStatus;
use App\Models\AccessPackage;
use App\Models\AccessPackageAssignment;
use App\Models\AccessPackageCatalog;
use App\Models\AccessReview;
use App\Models\AccessReviewInstance;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard returns correct stat counts', function () {
    $user = User::factory()->create();

    PartnerOrganization::factory()->count(3)->create();
    GuestUser::factory()->count(2)->create([
        'partner_organization_id' => PartnerOrganization::first()->id,
        'last_sign_in_at' => now()->subDays(1),
    ]);
    GuestUser::factory()->create([
        'partner_organization_id' => PartnerOrganization::first()->id,
        'invitation_status' => InvitationStatus::PendingAcceptance,
        'last_sign_in_at' => now()->subDays(1),
    ]);
    GuestUser::factory()->create([
        'partner_organization_id' => PartnerOrganization::first()->id,
        'last_sign_in_at' => now()->subDays(100),
    ]);
    GuestUser::factory()->create([
        'partner_organization_id' => PartnerOrganization::first()->id,
        'last_sign_in_at' => null,
    ]);

    $review = AccessReview::factory()->create();
    AccessReviewInstance::factory()->create([
        'access_review_id' => $review->id,
        'status' => ReviewInstanceStatus::InProgress,
        'due_at' => now()->subDays(5),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('stats', fn ($stats) => $stats
            ->where('total_partners', 3)
            ->where('total_guests', 5)
            ->where('pending_invitations', 1)
            ->where('stale_guests', 2)
            ->where('overdue_reviews', 1)
        )
    );
});

test('dashboard returns pending approvals ordered by requested_at asc', function () {
    $user = User::factory()->create();

    $catalog = AccessPackageCatalog::factory()->create();
    $package = AccessPackage::factory()->create(['catalog_id' => $catalog->id]);

    for ($i = 0; $i < 6; $i++) {
        AccessPackageAssignment::factory()->create([
            'access_package_id' => $package->id,
            'status' => AssignmentStatus::PendingApproval,
            'requested_at' => now()->subDays(6 - $i),
        ]);
    }

    AccessPackageAssignment::factory()->create([
        'access_package_id' => $package->id,
        'status' => AssignmentStatus::Delivered,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('pendingApprovals', 5)
    );

    $approvals = $response->original->getData()['page']['props']['pendingApprovals'];
    expect($approvals[0]['requested_at'])->toBeLessThan($approvals[4]['requested_at']);
});

test('dashboard returns attention partners with trust score below 70', function () {
    $user = User::factory()->create();

    PartnerOrganization::factory()->create(['trust_score' => 80]);
    PartnerOrganization::factory()->create(['trust_score' => 45]);
    PartnerOrganization::factory()->create(['trust_score' => 60]);
    PartnerOrganization::factory()->create(['trust_score' => null]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('attentionPartners', 2)
    );

    $partners = $response->original->getData()['page']['props']['attentionPartners'];
    expect($partners[0]['trust_score'])->toBeLessThanOrEqual($partners[1]['trust_score']);
});

test('dashboard returns max 10 recent activity entries', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('recentActivity')
    );
});
