<?php

use App\Models\Setting;
use App\Services\BridgeClient;
use App\Services\DTOs\BridgeHealth;
use App\Services\DTOs\SetLabelResult;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeConfigException;
use App\Services\Exceptions\BridgeLabelConflictException;
use App\Services\Exceptions\BridgeNetworkException;
use App\Services\Exceptions\BridgeSiteNotFoundException;
use App\Services\Exceptions\BridgeThrottleException;
use App\Services\Exceptions\BridgeUnavailableException;
use App\Services\Exceptions\BridgeUnknownException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Setting::set('sensitivity_sweep', 'bridge_url', 'http://bridge-test:8080');
    Setting::set('sensitivity_sweep', 'bridge_shared_secret', 'unit-secret');
});

test('setLabel returns a SetLabelResult on 200', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'siteUrl' => 'https://a/sites/x',
            'labelId' => 'lbl',
            'fastPath' => false,
        ], 200),
    ]);

    $client = app(BridgeClient::class);
    $result = $client->setLabel('https://a/sites/x', 'lbl', overwrite: false);

    expect($result)->toBeInstanceOf(SetLabelResult::class);
    expect($result->fastPath)->toBeFalse();
    expect($result->siteUrl)->toBe('https://a/sites/x');

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Bridge-Secret', 'unit-secret')
            && $request->url() === 'http://bridge-test:8080/v1/sites/label?overwrite=false'
            && $request['siteUrl'] === 'https://a/sites/x'
            && $request['labelId'] === 'lbl';
    });
});

test('setLabel with overwrite true sends overwrite query flag', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'siteUrl' => 'https://a/sites/x',
            'labelId' => 'lbl',
            'fastPath' => true,
        ], 200),
    ]);

    app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', overwrite: true);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'overwrite=true'));
});

test('setLabel maps 409 to BridgeLabelConflictException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'already_labeled', 'message' => 'conflict', 'requestId' => 'r1'],
        ], 409),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeLabelConflictException::class);
});

test('setLabel maps 404 to BridgeSiteNotFoundException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'nope', 'requestId' => 'r2'],
        ], 404),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeSiteNotFoundException::class);
});

test('setLabel maps 502 auth to BridgeAuthException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'auth', 'message' => 'fail', 'requestId' => 'r3'],
        ], 502),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeAuthException::class);
});

test('setLabel maps 502 throttle to BridgeThrottleException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'throttle', 'message' => 'slow down', 'requestId' => 'r4'],
        ], 502),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeThrottleException::class);
});

test('setLabel maps 502 network to BridgeNetworkException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'network', 'message' => 'timeout', 'requestId' => 'r5'],
        ], 502),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeNetworkException::class);
});

test('setLabel maps 502 certificate to BridgeConfigException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'certificate', 'message' => 'cert bad', 'requestId' => 'r6'],
        ], 502),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeConfigException::class);
});

test('setLabel maps unknown 502 code to BridgeUnknownException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'unknown', 'message' => 'weird', 'requestId' => 'r7'],
        ], 502),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeUnknownException::class);
});

test('setLabel maps connection failure to BridgeUnavailableException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeUnavailableException::class);
});

test('setLabel maps 401 (bad shared secret) to BridgeConfigException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'missing_secret', 'message' => 'bad secret', 'requestId' => 'r'],
        ], 401),
    ]);

    $thrown = null;
    try {
        app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false);
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(BridgeConfigException::class);
    expect($thrown->getMessage())->toContain('shared secret');
});

test('setLabel maps 400 (bad request from bridge) to BridgeUnknownException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label*' => Http::response([
            'error' => ['code' => 'bad_request', 'message' => 'siteUrl rejected', 'requestId' => 'r'],
        ], 400),
    ]);

    expect(fn () => app(BridgeClient::class)->setLabel('https://a/sites/x', 'lbl', false))
        ->toThrow(BridgeUnknownException::class);
});

test('readLabel connection failure maps to BridgeUnavailableException', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label:read*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('dns'),
    ]);

    expect(fn () => app(BridgeClient::class)->readLabel('https://a/sites/x'))
        ->toThrow(BridgeUnavailableException::class);
});

test('readLabel returns null when bridge reports unlabeled', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label:read*' => Http::response([
            'siteUrl' => 'https://a/sites/x',
            'labelId' => null,
        ], 200),
    ]);

    expect(app(BridgeClient::class)->readLabel('https://a/sites/x'))->toBeNull();
});

test('readLabel returns label GUID when labeled', function () {
    Http::fake([
        'bridge-test:8080/v1/sites/label:read*' => Http::response([
            'siteUrl' => 'https://a/sites/x',
            'labelId' => 'lbl-xyz',
        ], 200),
    ]);

    expect(app(BridgeClient::class)->readLabel('https://a/sites/x'))->toBe('lbl-xyz');
});

test('health returns BridgeHealth DTO', function () {
    Http::fake([
        'bridge-test:8080/health' => Http::response([
            'status' => 'ok',
            'cloudEnvironment' => 'commercial',
            'certThumbprint' => 'abc',
        ], 200),
    ]);

    $h = app(BridgeClient::class)->health();

    expect($h)->toBeInstanceOf(BridgeHealth::class);
    expect($h->status)->toBe('ok');
    expect($h->cloudEnvironment)->toBe('commercial');
});
