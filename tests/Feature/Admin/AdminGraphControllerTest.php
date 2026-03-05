<?php

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'approved_at' => now(),
    ]);
});

test('admin can view graph settings page', function () {
    $this->actingAs($this->admin)
        ->get('/admin/graph')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Graph'));
});

test('non-admin cannot access admin graph page', function () {
    $viewer = User::factory()->create([
        'role' => UserRole::Viewer,
        'approved_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->get('/admin/graph')
        ->assertForbidden();
});

test('operator cannot access admin graph page', function () {
    $operator = User::factory()->create([
        'role' => UserRole::Operator,
        'approved_at' => now(),
    ]);

    $this->actingAs($operator)
        ->get('/admin/graph')
        ->assertForbidden();
});

test('admin can update graph settings', function () {
    $this->actingAs($this->admin)
        ->put('/admin/graph', [
            'cloud_environment' => 'commercial',
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'client_id' => '660e8400-e29b-41d4-a716-446655440000',
            'client_secret' => 'new-secret',
            'scopes' => 'https://graph.microsoft.com/.default',
            'base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_interval_minutes' => '30',
        ])
        ->assertRedirect('/admin/graph');

    expect(Setting::get('graph', 'tenant_id'))->toBe('550e8400-e29b-41d4-a716-446655440000');
    expect(Setting::get('graph', 'client_secret'))->toBe('new-secret');
});

test('update with blank client_secret preserves existing secret', function () {
    Setting::set('graph', 'client_secret', 'existing-secret', encrypted: true);

    $this->actingAs($this->admin)
        ->put('/admin/graph', [
            'cloud_environment' => 'commercial',
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'client_id' => '660e8400-e29b-41d4-a716-446655440000',
            'client_secret' => null,
            'scopes' => 'https://graph.microsoft.com/.default',
            'base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_interval_minutes' => '15',
        ])
        ->assertRedirect('/admin/graph');

    expect(Setting::get('graph', 'client_secret'))->toBe('existing-secret');
});

test('graph settings page masks client secret', function () {
    Setting::set('graph', 'client_secret', 'my-long-secret-value', encrypted: true);

    $this->actingAs($this->admin)
        ->get('/admin/graph')
        ->assertInertia(fn ($page) => $page
            ->component('admin/Graph')
            ->where('settings.client_secret_masked', '••••••••alue')
        );
});

test('update clears cached graph token', function () {
    Cache::put('msgraph_access_token', 'old-token', 3600);

    $this->actingAs($this->admin)
        ->put('/admin/graph', [
            'cloud_environment' => 'commercial',
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'client_id' => '660e8400-e29b-41d4-a716-446655440000',
            'client_secret' => 'new-secret',
            'scopes' => 'https://graph.microsoft.com/.default',
            'base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_interval_minutes' => '15',
        ]);

    expect(Cache::has('msgraph_access_token'))->toBeFalse();
});

test('test connection succeeds with valid credentials', function () {
    Setting::set('graph', 'tenant_id', 'test-tenant');
    Setting::set('graph', 'client_id', 'test-client');
    Setting::set('graph', 'client_secret', 'test-secret', encrypted: true);
    Setting::set('graph', 'scopes', 'https://graph.microsoft.com/.default');

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'valid-token',
            'expires_in' => 3600,
        ]),
    ]);

    Cache::forget('msgraph_access_token');

    $this->actingAs($this->admin)
        ->post('/admin/graph/test')
        ->assertOk()
        ->assertJson(['success' => true]);
});

test('test connection fails with invalid credentials', function () {
    Setting::set('graph', 'tenant_id', 'bad-tenant');
    Setting::set('graph', 'client_id', 'bad-client');
    Setting::set('graph', 'client_secret', 'bad-secret', encrypted: true);
    Setting::set('graph', 'scopes', 'https://graph.microsoft.com/.default');

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'Invalid client credentials.',
        ], 401),
    ]);

    Cache::forget('msgraph_access_token');

    $this->actingAs($this->admin)
        ->post('/admin/graph/test')
        ->assertOk()
        ->assertJson(['success' => false]);
});

test('admin can update cloud environment setting', function () {
    $this->actingAs($this->admin)
        ->put('/admin/graph', [
            'cloud_environment' => 'gcc_high',
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'client_id' => '660e8400-e29b-41d4-a716-446655440000',
            'client_secret' => 'secret',
            'scopes' => 'https://graph.microsoft.us/.default',
            'base_url' => 'https://graph.microsoft.us/v1.0',
            'sync_interval_minutes' => '15',
        ])
        ->assertRedirect('/admin/graph');

    expect(Setting::get('graph', 'cloud_environment'))->toBe('gcc_high');
});

test('cloud environment rejects invalid values', function () {
    $this->actingAs($this->admin)
        ->put('/admin/graph', [
            'cloud_environment' => 'invalid_cloud',
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'client_id' => '660e8400-e29b-41d4-a716-446655440000',
            'scopes' => 'https://graph.microsoft.com/.default',
            'base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_interval_minutes' => '15',
        ])
        ->assertSessionHasErrors(['cloud_environment']);
});

test('graph settings page includes cloud environment', function () {
    Setting::set('graph', 'cloud_environment', 'gcc_high');

    $this->actingAs($this->admin)
        ->get('/admin/graph')
        ->assertInertia(fn ($page) => $page
            ->component('admin/Graph')
            ->where('settings.cloud_environment', 'gcc_high')
        );
});

test('consent url returns admin consent url', function () {
    Setting::set('graph', 'tenant_id', '550e8400-e29b-41d4-a716-446655440000');
    Setting::set('graph', 'client_id', '660e8400-e29b-41d4-a716-446655440000');
    Setting::set('graph', 'cloud_environment', 'commercial');

    $this->actingAs($this->admin)
        ->getJson('/admin/graph/consent')
        ->assertOk()
        ->assertJsonStructure(['url']);
});

test('consent url uses gcc high login url when configured', function () {
    Setting::set('graph', 'tenant_id', '550e8400-e29b-41d4-a716-446655440000');
    Setting::set('graph', 'client_id', '660e8400-e29b-41d4-a716-446655440000');
    Setting::set('graph', 'cloud_environment', 'gcc_high');

    $response = $this->actingAs($this->admin)
        ->getJson('/admin/graph/consent')
        ->assertOk();

    expect($response->json('url'))->toContain('login.microsoftonline.us');
});

test('consent callback renders success view', function () {
    $this->get('/admin/graph/consent/callback?admin_consent=True&tenant=some-tenant')
        ->assertOk()
        ->assertViewIs('admin.consent-callback')
        ->assertViewHas('success', true);
});

test('consent callback renders error view', function () {
    $this->get('/admin/graph/consent/callback?error=access_denied&error_description=Admin+denied')
        ->assertOk()
        ->assertViewIs('admin.consent-callback')
        ->assertViewHas('success', false)
        ->assertViewHas('error', 'Admin denied');
});

test('test connection logs GraphConnectionTested', function () {
    Setting::set('graph', 'tenant_id', 'test-tenant');
    Setting::set('graph', 'client_id', 'test-client');
    Setting::set('graph', 'client_secret', 'test-secret', encrypted: true);
    Setting::set('graph', 'scopes', 'https://graph.microsoft.com/.default');

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token']),
    ]);

    Cache::forget('msgraph_access_token');

    $this->actingAs($this->admin)->post('/admin/graph/test');

    $log = ActivityLog::where('action', ActivityAction::GraphConnectionTested)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($this->admin->id);
    expect($log->details['success'])->toBeTrue();
});

test('consent callback logs ConsentGranted on success', function () {
    $this->get('/admin/graph/consent/callback?admin_consent=True');

    $log = ActivityLog::where('action', ActivityAction::ConsentGranted)->first();
    expect($log)->not->toBeNull();
    expect($log->details['success'])->toBeTrue();
});

test('update validates required fields', function () {
    $this->actingAs($this->admin)
        ->put('/admin/graph', [])
        ->assertSessionHasErrors(['cloud_environment', 'tenant_id', 'client_id', 'scopes', 'base_url', 'sync_interval_minutes']);
});
