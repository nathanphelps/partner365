<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Services\CrossTenantPolicyService;

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

test('it lists all partner configurations', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners' => Http::response([
            'value' => [
                ['tenantId' => 'tenant-1', 'inboundTrust' => ['isMfaAccepted' => true]],
                ['tenantId' => 'tenant-2', 'inboundTrust' => null],
            ],
        ]),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $partners = $service->listPartners();

    expect($partners)->toHaveCount(2);
    expect($partners[0]['tenantId'])->toBe('tenant-1');
});

test('it gets a single partner configuration', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/tenant-1' => Http::response([
            'tenantId' => 'tenant-1',
            'inboundTrust' => ['isMfaAccepted' => true],
        ]),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $partner = $service->getPartner('tenant-1');

    expect($partner['tenantId'])->toBe('tenant-1');
    expect($partner['inboundTrust']['isMfaAccepted'])->toBeTrue();
});

test('it creates a partner configuration', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners' => Http::response([
            'tenantId' => 'new-tenant',
        ], 201),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $result = $service->createPartner('new-tenant', [
        'inboundTrust' => ['isMfaAccepted' => true],
    ]);

    expect($result['tenantId'])->toBe('new-tenant');
});

test('it updates a partner configuration', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/tenant-1' => Http::response([], 204),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $service->updatePartner('tenant-1', [
        'inboundTrust' => ['isMfaAccepted' => false],
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH');
});

test('it deletes a partner configuration', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/tenant-1' => Http::response([], 204),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $service->deletePartner('tenant-1');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

test('it gets default cross-tenant policy', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/default' => Http::response([
            'inboundTrust' => ['isMfaAccepted' => false],
            'b2bCollaborationInbound' => ['usersAndGroups' => ['accessType' => 'allowed']],
        ]),
    ]);

    $service = app(CrossTenantPolicyService::class);
    $defaults = $service->getDefaults();

    expect($defaults)->toHaveKey('inboundTrust');
});
