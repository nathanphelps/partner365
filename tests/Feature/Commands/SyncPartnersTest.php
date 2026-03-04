<?php

use App\Models\PartnerOrganization;
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

test('sync:partners creates partner records from graph api', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners' => Http::response([
            'value' => [
                [
                    'tenantId' => '550e8400-e29b-41d4-a716-446655440000',
                    'inboundTrust' => ['isMfaAccepted' => true, 'isCompliantDeviceAccepted' => false],
                    'b2bCollaborationInbound' => ['usersAndGroups' => ['accessType' => 'allowed']],
                    'b2bCollaborationOutbound' => ['usersAndGroups' => ['accessType' => 'blocked']],
                    'b2bDirectConnectInbound' => ['usersAndGroups' => ['accessType' => 'blocked']],
                ],
            ],
        ]),
        'graph.microsoft.com/v1.0/tenantRelationships/findTenantInformationByTenantId*' => Http::response([
            'tenantId' => '550e8400-e29b-41d4-a716-446655440000',
            'displayName' => 'Synced Corp',
            'defaultDomainName' => 'syncedcorp.com',
        ]),
    ]);

    $this->artisan('sync:partners')
        ->expectsOutput('Fetching partner configurations from Graph API...')
        ->expectsOutput('Synced 1 partner organizations.')
        ->assertSuccessful();

    expect(PartnerOrganization::count())->toBe(1);
    $partner = PartnerOrganization::first();
    expect($partner->display_name)->toBe('Synced Corp');
    expect($partner->domain)->toBe('syncedcorp.com');
    expect($partner->mfa_trust_enabled)->toBeTrue();
    expect($partner->b2b_inbound_enabled)->toBeTrue();
    expect($partner->b2b_outbound_enabled)->toBeFalse();
});

test('sync:partners updates existing partner records', function () {
    PartnerOrganization::factory()->create([
        'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
        'display_name' => 'Old Name',
        'mfa_trust_enabled' => false,
    ]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners' => Http::response([
            'value' => [
                [
                    'tenantId' => '550e8400-e29b-41d4-a716-446655440000',
                    'inboundTrust' => ['isMfaAccepted' => true],
                    'b2bCollaborationInbound' => [],
                    'b2bCollaborationOutbound' => [],
                    'b2bDirectConnectInbound' => [],
                ],
            ],
        ]),
        'graph.microsoft.com/v1.0/tenantRelationships/findTenantInformationByTenantId*' => Http::response([
            'tenantId' => '550e8400-e29b-41d4-a716-446655440000',
            'displayName' => 'Updated Corp',
            'defaultDomainName' => 'updated.com',
        ]),
    ]);

    $this->artisan('sync:partners')->assertSuccessful();

    expect(PartnerOrganization::count())->toBe(1);
    expect(PartnerOrganization::first()->display_name)->toBe('Updated Corp');
    expect(PartnerOrganization::first()->mfa_trust_enabled)->toBeTrue();
});
