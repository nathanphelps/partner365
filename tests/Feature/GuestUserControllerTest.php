<?php

use App\Enums\UserRole;
use App\Models\GuestUser;
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

test('guests cannot access guest users index', function () {
    $this->get(route('guests.index'))->assertRedirect(route('login'));
});

test('viewers can see guest users list', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);
    GuestUser::factory()->count(3)->create();

    $this->actingAs($user)
        ->get(route('guests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('guests/Index')
            ->has('guests.data', 3)
        );
});

test('operators can invite guest users', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/invitations' => Http::response([
            'id' => 'inv-1',
            'invitedUserEmailAddress' => 'guest@external.com',
            'invitedUserDisplayName' => 'Guest User',
            'status' => 'PendingAcceptance',
            'invitedUser' => ['id' => 'entra-user-id-1', 'userPrincipalName' => 'guest_external.com#EXT#@contoso.com'],
        ], 201),
    ]);

    $this->actingAs($user)
        ->post(route('guests.store'), [
            'email' => 'guest@external.com',
            'redirect_url' => 'https://myapp.com',
            'send_email' => true,
        ])
        ->assertRedirect(route('guests.index'));

    expect(GuestUser::count())->toBe(1);
    expect(GuestUser::first()->email)->toBe('guest@external.com');
    expect(GuestUser::first()->invited_by_user_id)->toBe($user->id);
});

test('viewers cannot invite guest users', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->post(route('guests.store'), [
            'email' => 'guest@external.com',
            'redirect_url' => 'https://myapp.com',
        ])
        ->assertForbidden();
});

test('only admins can delete guest users', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $guest = GuestUser::factory()->create();

    $this->actingAs($operator)
        ->delete(route('guests.destroy', $guest))
        ->assertForbidden();
});
