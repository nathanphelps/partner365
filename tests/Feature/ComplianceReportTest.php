<?php

use App\Enums\UserRole;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => UserRole::Admin]);
});

test('unauthenticated users cannot access reports', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

test('authenticated users can access reports page', function () {
    $this->actingAs($this->user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/Index')
            ->has('partnerCompliance')
            ->has('guestHealth')
            ->has('summary')
        );
});

test('all roles can access reports', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(route('reports.index'))
        ->assertOk();
})->with([UserRole::Admin, UserRole::Operator, UserRole::Viewer]);

test('compliance score is calculated correctly', function () {
    // Compliant: MFA enabled, device trust enabled, not overly permissive
    PartnerOrganization::factory()->create([
        'mfa_trust_enabled' => true,
        'device_trust_enabled' => true,
        'b2b_inbound_enabled' => true,
        'b2b_outbound_enabled' => false,
    ]);

    // Non-compliant: no MFA
    PartnerOrganization::factory()->create([
        'mfa_trust_enabled' => false,
        'device_trust_enabled' => true,
        'b2b_inbound_enabled' => false,
        'b2b_outbound_enabled' => false,
    ]);

    // Non-compliant: overly permissive
    PartnerOrganization::factory()->create([
        'mfa_trust_enabled' => true,
        'device_trust_enabled' => true,
        'b2b_inbound_enabled' => true,
        'b2b_outbound_enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.compliance_score', 33)
            ->where('summary.partners_with_issues', 3) // all 3 have no CA policies
            ->where('partnerCompliance.no_mfa_count', 1)
            ->where('partnerCompliance.overly_permissive_count', 1)
        );
});

test('stale guest buckets are computed correctly', function () {
    $partner = PartnerOrganization::factory()->create();

    // Active guest (signed in 10 days ago)
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => now()->subDays(10),
    ]);

    // 30+ day stale guest
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => now()->subDays(45),
    ]);

    // 60+ day stale guest
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => now()->subDays(75),
    ]);

    // 90+ day stale guest
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => now()->subDays(100),
    ]);

    // Never signed in
    GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'last_sign_in_at' => null,
    ]);

    $this->actingAs($this->user)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('guestHealth.stale_30_plus', 4)
            ->where('guestHealth.stale_60_plus', 3)
            ->where('guestHealth.stale_90_plus', 2)
            ->where('guestHealth.never_signed_in', 1)
        );
});

test('csv export returns downloadable file with correct headers', function () {
    PartnerOrganization::factory()->create(['display_name' => 'Acme Corp']);
    GuestUser::factory()->create(['email' => 'guest@acme.com']);

    $response = $this->actingAs($this->user)
        ->get(route('reports.export'));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $response->assertHeader('Content-Disposition', 'attachment; filename="compliance-report.csv"');

    $content = $response->streamedContent();
    expect($content)->toContain('Partner Name');
    expect($content)->toContain('Acme Corp');
    expect($content)->toContain('Guest Email');
    expect($content)->toContain('guest@acme.com');
});

test('unauthenticated users cannot export csv', function () {
    $this->get(route('reports.export'))->assertRedirect(route('login'));
});
