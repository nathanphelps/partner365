<?php

use App\Models\ConditionalAccessPolicy;
use App\Models\PartnerOrganization;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->actingAs($this->user);
});

test('index page renders with policies', function () {
    ConditionalAccessPolicy::create([
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
    $viewer = User::factory()->create(['role' => 'viewer', 'approved_at' => now()]);
    $this->actingAs($viewer);

    $response = $this->get('/conditional-access');

    $response->assertOk();
});
