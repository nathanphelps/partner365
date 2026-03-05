<?php

namespace App\Services;

use App\Models\ConditionalAccessPolicy;
use App\Models\PartnerOrganization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ConditionalAccessPolicyService
{
    public function __construct(
        private MicrosoftGraphService $graph,
    ) {}

    public function syncPolicies(): int
    {
        $graphPolicies = $this->fetchPoliciesFromGraph();
        $guestPolicies = $this->filterGuestPolicies($graphPolicies);

        $syncedPolicyIds = [];

        foreach ($guestPolicies as $graphPolicy) {
            $parsed = $this->parsePolicy($graphPolicy);
            $policy = ConditionalAccessPolicy::updateOrCreate(
                ['policy_id' => $graphPolicy['id']],
                array_merge($parsed, ['synced_at' => now()])
            );

            $this->buildPartnerMappings($policy, $graphPolicy);
            $syncedPolicyIds[] = $policy->id;
        }

        ConditionalAccessPolicy::whereNotIn('id', $syncedPolicyIds)->delete();

        return count($syncedPolicyIds);
    }

    public function getUncoveredPartners(): Collection
    {
        return PartnerOrganization::whereDoesntHave('conditionalAccessPolicies')->get();
    }

    private function fetchPoliciesFromGraph(): array
    {
        $response = $this->graph->get('/identity/conditionalAccess/policies');

        if (! array_key_exists('value', $response)) {
            Log::error('Graph API response missing "value" key for conditional access policies', [
                'response_keys' => array_keys($response),
            ]);
            throw new \RuntimeException('Unexpected Graph API response structure for conditional access policies');
        }

        return $response['value'];
    }

    private function filterGuestPolicies(array $policies): array
    {
        return array_filter($policies, function (array $policy) {
            return isset($policy['conditions']['users']['includeGuestsOrExternalUsers']);
        });
    }

    private function parsePolicy(array $graphPolicy): array
    {
        $guestConfig = $graphPolicy['conditions']['users']['includeGuestsOrExternalUsers'];
        $externalTenants = $guestConfig['externalTenants'] ?? [];
        $membershipKind = $externalTenants['membershipKind'] ?? 'all';

        $apps = $graphPolicy['conditions']['applications']['includeApplications'] ?? [];
        $targetApps = in_array('All', $apps) ? 'all' : implode(', ', $apps);

        $grantControls = $graphPolicy['grantControls']['builtInControls'] ?? [];
        $sessionControls = [];
        if (! empty($graphPolicy['sessionControls'])) {
            foreach ($graphPolicy['sessionControls'] as $key => $value) {
                if ($value !== null && $key !== '@odata.type') {
                    $sessionControls[] = $key;
                }
            }
        }

        return [
            'display_name' => $graphPolicy['displayName'],
            'state' => $graphPolicy['state'],
            'guest_or_external_user_types' => $guestConfig['guestOrExternalUserTypes'] ?? '',
            'external_tenant_scope' => $membershipKind === 'all' ? 'all' : 'specific',
            'external_tenant_ids' => $membershipKind === 'enumerated' ? ($externalTenants['members'] ?? []) : null,
            'target_applications' => $targetApps,
            'grant_controls' => $grantControls,
            'session_controls' => $sessionControls,
            'raw_policy_json' => $graphPolicy,
        ];
    }

    private function buildPartnerMappings(ConditionalAccessPolicy $policy, array $graphPolicy): void
    {
        $guestConfig = $graphPolicy['conditions']['users']['includeGuestsOrExternalUsers'];
        $userTypes = explode(',', $guestConfig['guestOrExternalUserTypes'] ?? '');
        $externalTenants = $guestConfig['externalTenants'] ?? [];
        $membershipKind = $externalTenants['membershipKind'] ?? 'all';

        $partners = match ($membershipKind) {
            'all' => PartnerOrganization::all(),
            'enumerated' => PartnerOrganization::whereIn('tenant_id', $externalTenants['members'] ?? [])->get(),
            default => collect(),
        };

        $pivotData = [];
        foreach ($partners as $partner) {
            foreach ($userTypes as $userType) {
                $userType = trim($userType);
                if ($userType) {
                    $pivotData[$partner->id] = ['matched_user_type' => $userType];
                }
            }
        }

        $policy->partners()->sync($pivotData);
    }
}
