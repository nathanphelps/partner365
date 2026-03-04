<?php

use App\Services\TenantResolverService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

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

test('it resolves tenant info by tenant id', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/tenantRelationships/findTenantInformationByTenantId*' => Http::response([
            'tenantId' => 'abc-123',
            'displayName' => 'Contoso Ltd',
            'defaultDomainName' => 'contoso.com',
        ]),
    ]);

    $service = app(TenantResolverService::class);
    $info = $service->resolve('abc-123');

    expect($info['displayName'])->toBe('Contoso Ltd');
    expect($info['defaultDomainName'])->toBe('contoso.com');
});

test('it validates tenant id format', function () {
    $service = app(TenantResolverService::class);

    expect($service->isValidTenantId('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
    expect($service->isValidTenantId('not-a-guid'))->toBeFalse();
    expect($service->isValidTenantId(''))->toBeFalse();
});
