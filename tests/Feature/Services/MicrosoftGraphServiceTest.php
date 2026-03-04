<?php

use App\Services\MicrosoftGraphService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant-id',
        'graph.client_id' => 'test-client-id',
        'graph.client_secret' => 'test-client-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
    ]);

    Cache::forget('msgraph_access_token');
});

test('it acquires an access token via client credentials', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token-123',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
    ]);

    $service = app(MicrosoftGraphService::class);
    $token = $service->getAccessToken();

    expect($token)->toBe('fake-token-123');
});

test('it caches the access token', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token-456',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
    ]);

    $service = app(MicrosoftGraphService::class);
    $service->getAccessToken();
    $service->getAccessToken();

    Http::assertSentCount(1);
});

test('it makes authenticated GET requests to graph api', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/*' => Http::response([
            'value' => [['id' => '123']],
        ]),
    ]);

    $service = app(MicrosoftGraphService::class);
    $response = $service->get('/users');

    expect($response['value'])->toHaveCount(1);
    expect($response['value'][0]['id'])->toBe('123');
});

test('it makes authenticated POST requests to graph api', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/*' => Http::response([
            'id' => 'new-id',
        ], 201),
    ]);

    $service = app(MicrosoftGraphService::class);
    $response = $service->post('/invitations', ['invitedUserEmailAddress' => 'test@example.com']);

    expect($response['id'])->toBe('new-id');
});

test('it makes authenticated PATCH requests to graph api', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/*' => Http::response([], 204),
    ]);

    $service = app(MicrosoftGraphService::class);
    $response = $service->patch('/users/123', ['displayName' => 'Updated']);

    expect($response)->toBeEmpty();
});

test('it makes authenticated DELETE requests to graph api', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/*' => Http::response([], 204),
    ]);

    $service = app(MicrosoftGraphService::class);
    $response = $service->delete('/users/123');

    expect($response)->toBeEmpty();
});

test('it throws on graph api error responses', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/*' => Http::response([
            'error' => [
                'code' => 'Request_ResourceNotFound',
                'message' => 'Resource not found.',
            ],
        ], 404),
    ]);

    $service = app(MicrosoftGraphService::class);
    $service->get('/users/nonexistent');
})->throws(\App\Exceptions\GraphApiException::class);
