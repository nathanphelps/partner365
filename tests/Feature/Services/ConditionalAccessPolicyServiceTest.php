<?php

use App\Models\ConditionalAccessPolicy;
use App\Models\PartnerOrganization;
use App\Services\ConditionalAccessPolicyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

function makeCaPolicy(array $overrides = []): array
{
    return array_merge([
        'id' => 'policy-1',
        'displayName' => 'Require MFA for guests',
        'state' => 'enabled',
        'conditions' => [
            'users' => [
                'includeGuestsOrExternalUsers' => [
                    'guestOrExternalUserTypes' => 'b2bCollaborationGuest',
                    'externalTenants' => [
                        '@odata.type' => '#microsoft.graph.conditionalAccessAllExternalTenants',
                        'membershipKind' => 'all',
                    ],
                ],
            ],
            'applications' => [
                'includeApplications' => ['All'],
            ],
        ],
        'grantControls' => [
            'builtInControls' => ['mfa'],
            'operator' => 'OR',
        ],
        'sessionControls' => null,
    ], $overrides);
}

function fakeGraphWithPolicies(array $policies): void
{
    Http::fake([
        'login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fake-token',
            'expires_in' => 3600,
        ]),
        'graph.microsoft.com/v1.0/identity/conditionalAccess/policies' => Http::response([
            'value' => $policies,
        ]),
    ]);
}

test('syncPolicies upserts policies from Graph API', function () {
    fakeGraphWithPolicies([makeCaPolicy()]);

    $service = app(ConditionalAccessPolicyService::class);
    $service->syncPolicies();

    expect(ConditionalAccessPolicy::count())->toBe(1);
    $policy = ConditionalAccessPolicy::first();
    expect($policy->policy_id)->toBe('policy-1');
    expect($policy->display_name)->toBe('Require MFA for guests');
    expect($policy->state)->toBe('enabled');
    expect($policy->grant_controls)->toBe(['mfa']);
});

test('syncPolicies ignores policies without guest conditions', function () {
    $nonGuestPolicy = [
        'id' => 'policy-internal',
        'displayName' => 'Internal MFA',
        'state' => 'enabled',
        'conditions' => [
            'users' => [
                'includeUsers' => ['All'],
            ],
            'applications' => [
                'includeApplications' => ['All'],
            ],
        ],
        'grantControls' => [
            'builtInControls' => ['mfa'],
            'operator' => 'OR',
        ],
        'sessionControls' => null,
    ];

    fakeGraphWithPolicies([$nonGuestPolicy, makeCaPolicy()]);

    $service = app(ConditionalAccessPolicyService::class);
    $service->syncPolicies();

    expect(ConditionalAccessPolicy::count())->toBe(1);
    expect(ConditionalAccessPolicy::first()->policy_id)->toBe('policy-1');
});

test('syncPolicies maps policies to partners with all tenants scope', function () {
    $partner = PartnerOrganization::factory()->create(['tenant_id' => 'tenant-abc']);

    fakeGraphWithPolicies([makeCaPolicy()]);

    $service = app(ConditionalAccessPolicyService::class);
    $service->syncPolicies();

    expect($partner->conditionalAccessPolicies()->count())->toBe(1);
    expect($partner->conditionalAccessPolicies()->first()->pivot->matched_user_type)->toBe('b2bCollaborationGuest');
});

test('syncPolicies maps policies only to specific tenants when scope is enumerated', function () {
    $matchedPartner = PartnerOrganization::factory()->create(['tenant_id' => 'tenant-match']);
    $unmatchedPartner = PartnerOrganization::factory()->create(['tenant_id' => 'tenant-other']);

    $policy = makeCaPolicy([
        'conditions' => [
            'users' => [
                'includeGuestsOrExternalUsers' => [
                    'guestOrExternalUserTypes' => 'b2bCollaborationGuest',
                    'externalTenants' => [
                        '@odata.type' => '#microsoft.graph.conditionalAccessEnumeratedExternalTenants',
                        'membershipKind' => 'enumerated',
                        'members' => ['tenant-match'],
                    ],
                ],
            ],
            'applications' => [
                'includeApplications' => ['All'],
            ],
        ],
    ]);

    fakeGraphWithPolicies([$policy]);

    $service = app(ConditionalAccessPolicyService::class);
    $service->syncPolicies();

    expect($matchedPartner->conditionalAccessPolicies()->count())->toBe(1);
    expect($unmatchedPartner->conditionalAccessPolicies()->count())->toBe(0);
});

test('syncPolicies removes stale policies on re-sync', function () {
    ConditionalAccessPolicy::create([
        'policy_id' => 'stale-policy',
        'display_name' => 'Old Policy',
        'state' => 'enabled',
        'synced_at' => now()->subDay(),
    ]);

    fakeGraphWithPolicies([makeCaPolicy()]);

    $service = app(ConditionalAccessPolicyService::class);
    $service->syncPolicies();

    expect(ConditionalAccessPolicy::count())->toBe(1);
    expect(ConditionalAccessPolicy::first()->policy_id)->toBe('policy-1');
});

test('getUncoveredPartners returns partners with no CA policies', function () {
    $covered = PartnerOrganization::factory()->create();
    $uncovered = PartnerOrganization::factory()->create();

    $policy = ConditionalAccessPolicy::create([
        'policy_id' => 'p1',
        'display_name' => 'Test',
        'state' => 'enabled',
        'synced_at' => now(),
    ]);
    $policy->partners()->attach($covered->id, ['matched_user_type' => 'b2bCollaborationGuest']);

    $service = app(ConditionalAccessPolicyService::class);
    $result = $service->getUncoveredPartners();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($uncovered->id);
});
