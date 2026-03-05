<?php

use App\Enums\UserRole;
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
            'direct_connect_inbound_enabled' => false,
            'direct_connect_outbound_enabled' => false,
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
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'abc-def-123-456-789012345678']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/*' => Http::response([], 204),
    ]);

    $this->actingAs($user)
        ->patch(route('partners.update', $partner), [
            'category' => 'strategic_partner',
            'notes' => 'Updated notes',
            'mfa_trust_enabled' => true,
            'b2b_inbound_enabled' => true,
            'b2b_outbound_enabled' => true,
            'device_trust_enabled' => false,
            'direct_connect_inbound_enabled' => false,
            'direct_connect_outbound_enabled' => false,
        ])
        ->assertRedirect();

    expect($partner->fresh()->category->value)->toBe('strategic_partner');
});

test('only admins can delete partners', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $partner = PartnerOrganization::factory()->create();

    $this->actingAs($operator)
        ->delete(route('partners.destroy', $partner))
        ->assertForbidden();
});

test('admins can delete partners', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'abc-def-123-456-789012345678']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/*' => Http::response([], 204),
    ]);

    $this->actingAs($admin)
        ->delete(route('partners.destroy', $partner))
        ->assertRedirect(route('partners.index'));

    expect(PartnerOrganization::count())->toBe(0);
});
