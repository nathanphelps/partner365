<?php

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeSocialiteUser(array $overrides = []): void
{
    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = $overrides['id'] ?? 'entra-object-id-123';
    $socialiteUser->name = $overrides['name'] ?? 'Test User';
    $socialiteUser->email = $overrides['email'] ?? 'test@example.com';
    $socialiteUser->token = 'fake-token';

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
}

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'approved_at' => now(),
    ]);
});

// --- User model ---

test('user model has entra_id attribute', function () {
    $user = User::factory()->create(['entra_id' => 'abc-123']);

    expect($user->entra_id)->toBe('abc-123');
});

// --- Admin SSO settings ---

test('admin can view SSO settings', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.sso.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Sso'));
});

test('non-admin cannot view SSO settings', function () {
    $viewer = User::factory()->create([
        'role' => UserRole::Viewer,
        'approved_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->get(route('admin.sso.edit'))
        ->assertForbidden();
});

test('admin can save SSO settings', function () {
    $this->actingAs($this->admin)->put(route('admin.sso.update'), [
        'enabled' => true,
        'auto_approve' => false,
        'default_role' => 'viewer',
        'group_mapping_enabled' => false,
        'group_mappings' => [],
        'restrict_provisioning_to_mapped_groups' => false,
    ])->assertRedirect();

    expect(Setting::get('sso', 'enabled'))->toBe('true');
    expect(Setting::get('sso', 'auto_approve'))->toBe('false');
    expect(Setting::get('sso', 'default_role'))->toBe('viewer');
});

test('SSO settings validate default_role', function () {
    $this->actingAs($this->admin)->put(route('admin.sso.update'), [
        'enabled' => true,
        'auto_approve' => false,
        'default_role' => 'superadmin',
        'group_mapping_enabled' => false,
        'group_mappings' => [],
        'restrict_provisioning_to_mapped_groups' => false,
    ])->assertSessionHasErrors('default_role');
});

test('SSO settings validate group_mappings structure', function () {
    $this->actingAs($this->admin)->put(route('admin.sso.update'), [
        'enabled' => true,
        'auto_approve' => false,
        'default_role' => 'viewer',
        'group_mapping_enabled' => true,
        'group_mappings' => [
            ['entra_group_id' => '', 'entra_group_name' => 'Admins', 'role' => 'admin'],
        ],
        'restrict_provisioning_to_mapped_groups' => false,
    ])->assertSessionHasErrors('group_mappings.0.entra_group_id');
});

test('SSO edit page shows graph credentials status', function () {
    Setting::set('graph', 'client_id', 'test-client-id');
    Setting::set('graph', 'tenant_id', 'test-tenant-id');

    $this->actingAs($this->admin)
        ->get(route('admin.sso.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Sso')
            ->where('graphConfigured', true)
        );
});

// --- SSO auth flow ---

test('SSO callback creates new user when auto_approve is on', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'auto_approve', 'true');
    Setting::set('sso', 'default_role', 'viewer');

    fakeSocialiteUser();

    $response = $this->get('/auth/sso/callback');

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::where('entra_id', 'entra-object-id-123')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->role->value)->toBe('viewer');
    expect($user->isApproved())->toBeTrue();
});

test('SSO callback creates new user pending approval when auto_approve is off', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'auto_approve', 'false');
    Setting::set('sso', 'default_role', 'viewer');

    fakeSocialiteUser();

    $this->get('/auth/sso/callback');

    $this->assertAuthenticated();

    $user = User::where('entra_id', 'entra-object-id-123')->first();
    expect($user->isApproved())->toBeFalse();
});

test('SSO callback logs in existing user matched by entra_id', function () {
    Setting::set('sso', 'enabled', 'true');

    $existing = User::factory()->create([
        'entra_id' => 'entra-object-id-123',
        'email' => 'old@example.com',
        'approved_at' => now(),
    ]);

    fakeSocialiteUser(['email' => 'new@example.com']);

    $this->get('/auth/sso/callback');

    $this->assertAuthenticatedAs($existing);
    expect($existing->fresh()->name)->toBe('Test User');
});

test('SSO callback matches existing user by email and sets entra_id', function () {
    Setting::set('sso', 'enabled', 'true');

    $existing = User::factory()->create([
        'email' => 'test@example.com',
        'entra_id' => null,
        'approved_at' => now(),
    ]);

    fakeSocialiteUser();

    $this->get('/auth/sso/callback');

    $this->assertAuthenticatedAs($existing);
    expect($existing->fresh()->entra_id)->toBe('entra-object-id-123');
});

test('SSO callback redirects to login when SSO is disabled', function () {
    Setting::set('sso', 'enabled', 'false');

    $this->get('/auth/sso')
        ->assertRedirect(route('login'));

    $this->get('/auth/sso/callback')
        ->assertRedirect(route('login'));
});

// --- Login page ---

test('login page shows SSO button when SSO is enabled', function () {
    Setting::set('sso', 'enabled', 'true');

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/Login')
            ->where('ssoEnabled', true)
        );
});

test('login page hides SSO button when SSO is disabled', function () {
    Setting::set('sso', 'enabled', 'false');

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/Login')
            ->where('ssoEnabled', false)
        );
});

// --- Group mapping ---

test('SSO callback assigns role from group mapping', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'auto_approve', 'true');
    Setting::set('sso', 'default_role', 'viewer');
    Setting::set('sso', 'group_mapping_enabled', 'true');
    Setting::set('sso', 'group_mappings', json_encode([
        ['entra_group_id' => 'group-admin-id', 'entra_group_name' => 'P365 Admins', 'role' => 'admin'],
        ['entra_group_id' => 'group-operator-id', 'entra_group_name' => 'P365 Operators', 'role' => 'operator'],
    ]));

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'Bearer']),
        'graph.microsoft.com/v1.0/users/entra-object-id-123/memberOf*' => Http::response([
            'value' => [
                ['id' => 'group-operator-id', '@odata.type' => '#microsoft.graph.group'],
                ['id' => 'unrelated-group', '@odata.type' => '#microsoft.graph.group'],
            ],
        ]),
    ]);

    fakeSocialiteUser();

    $this->get('/auth/sso/callback');

    $user = User::where('entra_id', 'entra-object-id-123')->first();
    expect($user->role->value)->toBe('operator');
});

test('SSO callback picks highest privilege role when multiple groups match', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'auto_approve', 'true');
    Setting::set('sso', 'default_role', 'viewer');
    Setting::set('sso', 'group_mapping_enabled', 'true');
    Setting::set('sso', 'group_mappings', json_encode([
        ['entra_group_id' => 'group-admin-id', 'entra_group_name' => 'P365 Admins', 'role' => 'admin'],
        ['entra_group_id' => 'group-operator-id', 'entra_group_name' => 'P365 Operators', 'role' => 'operator'],
    ]));

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'Bearer']),
        'graph.microsoft.com/v1.0/users/entra-object-id-123/memberOf*' => Http::response([
            'value' => [
                ['id' => 'group-admin-id', '@odata.type' => '#microsoft.graph.group'],
                ['id' => 'group-operator-id', '@odata.type' => '#microsoft.graph.group'],
            ],
        ]),
    ]);

    fakeSocialiteUser();

    $this->get('/auth/sso/callback');

    $user = User::where('entra_id', 'entra-object-id-123')->first();
    expect($user->role->value)->toBe('admin');
});

test('SSO callback denies user not in mapped groups when restrict is on', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'group_mapping_enabled', 'true');
    Setting::set('sso', 'restrict_provisioning_to_mapped_groups', 'true');
    Setting::set('sso', 'default_role', 'viewer');
    Setting::set('sso', 'group_mappings', json_encode([
        ['entra_group_id' => 'group-admin-id', 'entra_group_name' => 'P365 Admins', 'role' => 'admin'],
    ]));

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'Bearer']),
        'graph.microsoft.com/v1.0/users/entra-object-id-123/memberOf*' => Http::response([
            'value' => [
                ['id' => 'unrelated-group', '@odata.type' => '#microsoft.graph.group'],
            ],
        ]),
    ]);

    fakeSocialiteUser();

    $response = $this->get('/auth/sso/callback');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::where('entra_id', 'entra-object-id-123')->exists())->toBeFalse();
});

test('SSO callback falls back to default role when no groups match and restrict is off', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'auto_approve', 'true');
    Setting::set('sso', 'default_role', 'operator');
    Setting::set('sso', 'group_mapping_enabled', 'true');
    Setting::set('sso', 'restrict_provisioning_to_mapped_groups', 'false');
    Setting::set('sso', 'group_mappings', json_encode([
        ['entra_group_id' => 'group-admin-id', 'entra_group_name' => 'P365 Admins', 'role' => 'admin'],
    ]));

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'Bearer']),
        'graph.microsoft.com/v1.0/users/entra-object-id-123/memberOf*' => Http::response([
            'value' => [],
        ]),
    ]);

    fakeSocialiteUser();

    $this->get('/auth/sso/callback');

    $user = User::where('entra_id', 'entra-object-id-123')->first();
    expect($user->role->value)->toBe('operator');
});
