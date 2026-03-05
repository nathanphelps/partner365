<?php

use App\Services\GuestUserService;
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

test('it lists guest users', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users*' => Http::response([
            'value' => [
                ['id' => 'u1', 'displayName' => 'Guest One', 'userType' => 'Guest'],
                ['id' => 'u2', 'displayName' => 'Guest Two', 'userType' => 'Guest'],
            ],
        ]),
    ]);

    $service = app(GuestUserService::class);
    $guests = $service->listGuests();

    expect($guests)->toHaveCount(2);
});

test('it creates an invitation', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/invitations' => Http::response([
            'id' => 'inv-1',
            'invitedUserEmailAddress' => 'guest@partner.com',
            'status' => 'PendingAcceptance',
            'invitedUser' => ['id' => 'user-id-1'],
        ], 201),
    ]);

    $service = app(GuestUserService::class);
    $result = $service->invite('guest@partner.com', 'https://myapp.com');

    expect($result['invitedUserEmailAddress'])->toBe('guest@partner.com');
    expect($result['invitedUser']['id'])->toBe('user-id-1');
});

test('it gets a single guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1*' => Http::response([
            'id' => 'u1',
            'displayName' => 'Guest One',
            'mail' => 'guest@partner.com',
        ]),
    ]);

    $service = app(GuestUserService::class);
    $user = $service->getUser('u1');

    expect($user['displayName'])->toBe('Guest One');
});

test('it deletes a guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1' => Http::response([], 204),
    ]);

    $service = app(GuestUserService::class);
    $service->deleteUser('u1');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

test('it enables a guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1' => Http::response([], 204),
    ]);

    $service = app(GuestUserService::class);
    $service->enableUser('u1');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/users/u1')
        && $request->data()['accountEnabled'] === true
    );
});

test('it disables a guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1' => Http::response([], 204),
    ]);

    $service = app(GuestUserService::class);
    $service->disableUser('u1');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/users/u1')
        && $request->data()['accountEnabled'] === false
    );
});

test('it resends an invitation', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/invitations' => Http::response([
            'id' => 'inv-2',
            'invitedUserEmailAddress' => 'guest@partner.com',
            'status' => 'PendingAcceptance',
            'invitedUser' => ['id' => 'u1'],
        ], 201),
    ]);

    $service = app(GuestUserService::class);
    $result = $service->resendInvitation('guest@partner.com', 'https://myapp.com');

    expect($result['invitedUserEmailAddress'])->toBe('guest@partner.com');
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/invitations')
    );
});
