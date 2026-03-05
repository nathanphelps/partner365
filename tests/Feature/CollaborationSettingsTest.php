<?php

use App\Enums\UserRole;
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

test('non-admins cannot access collaboration settings', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);

    $this->actingAs($user)
        ->get(route('admin.collaboration.edit'))
        ->assertForbidden();
});

test('admins can view collaboration settings', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/authorizationPolicy' => Http::response([
            'allowInvitesFrom' => 'adminsAndGuestInviters',
            'allowedToInvite' => [],
            'blockedFromInvite' => [],
        ]),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.collaboration.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Collaboration')
            ->has('settings')
        );
});

test('admins can update collaboration settings', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/authorizationPolicy' => Http::response([], 204),
    ]);

    $this->actingAs($admin)
        ->put(route('admin.collaboration.update'), [
            'allow_invites_from' => 'adminsAndGuestInviters',
            'domain_restriction_mode' => 'none',
            'allowed_domains' => [],
            'blocked_domains' => [],
        ])
        ->assertRedirect();
});

test('admins can set domain allow list', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/authorizationPolicy' => Http::response([], 204),
    ]);

    $this->actingAs($admin)
        ->put(route('admin.collaboration.update'), [
            'allow_invites_from' => 'everyone',
            'domain_restriction_mode' => 'allowList',
            'allowed_domains' => ['contoso.com', 'fabrikam.com'],
            'blocked_domains' => [],
        ])
        ->assertRedirect();
});

test('operators can update tenant restrictions on partners', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $partner = \App\Models\PartnerOrganization::factory()->create(['tenant_id' => 'abc-def-123-456-789012345678']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/*' => Http::response([], 204),
    ]);

    $this->actingAs($user)
        ->patch(route('partners.update', $partner), [
            'tenant_restrictions_enabled' => true,
            'tenant_restrictions_json' => [
                'applications' => [
                    'accessType' => 'blocked',
                    'targets' => [
                        ['target' => 'some-app-id', 'targetType' => 'application'],
                    ],
                ],
                'usersAndGroups' => [
                    'accessType' => 'allowed',
                    'targets' => [
                        ['target' => 'AllUsers', 'targetType' => 'user'],
                    ],
                ],
            ],
        ])
        ->assertRedirect();

    $partner->refresh();
    expect($partner->tenant_restrictions_enabled)->toBeTrue();
    expect($partner->tenant_restrictions_json['applications']['accessType'])->toBe('blocked');
});

test('disabling tenant restrictions clears json', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);
    $partner = \App\Models\PartnerOrganization::factory()->create([
        'tenant_id' => 'abc-def-123-456-789012345678',
        'tenant_restrictions_enabled' => true,
        'tenant_restrictions_json' => ['applications' => ['accessType' => 'blocked']],
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/*' => Http::response([], 204),
    ]);

    $this->actingAs($user)
        ->patch(route('partners.update', $partner), [
            'tenant_restrictions_enabled' => false,
            'tenant_restrictions_json' => null,
        ])
        ->assertRedirect();

    $partner->refresh();
    expect($partner->tenant_restrictions_enabled)->toBeFalse();
    expect($partner->tenant_restrictions_json)->toBeNull();
});
