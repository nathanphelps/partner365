<?php

namespace App\Services;

use App\Exceptions\GraphApiException;
use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\SensitivityLabelPolicy;
use App\Models\SiteSensitivityLabel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SensitivityLabelService
{
    public function __construct(
        private MicrosoftGraphService $graph,
    ) {}

    public function syncLabels(): array
    {
        $graphLabels = $this->fetchLabelsFromGraph();

        $syncedLabelIds = [];

        // First pass: create/update all labels (without parent references)
        foreach ($graphLabels as $graphLabel) {
            $parsed = $this->parseLabel($graphLabel);
            $label = SensitivityLabel::updateOrCreate(
                ['label_id' => $graphLabel['id']],
                array_merge($parsed, ['synced_at' => now()])
            );
            $syncedLabelIds[] = $label->id;
        }

        // Second pass: link parent-child relationships
        foreach ($graphLabels as $graphLabel) {
            if (! empty($graphLabel['parent']['id'])) {
                $parent = SensitivityLabel::where('label_id', $graphLabel['parent']['id'])->first();
                if ($parent) {
                    SensitivityLabel::where('label_id', $graphLabel['id'])
                        ->update(['parent_label_id' => $parent->id]);
                }
            }
        }

        SensitivityLabel::whereNotIn('id', $syncedLabelIds)->delete();

        return ['labels_synced' => count($syncedLabelIds)];
    }

    public function syncPolicies(): int
    {
        $graphPolicies = $this->fetchLabelPoliciesFromGraph();

        $syncedIds = [];

        foreach ($graphPolicies as $graphPolicy) {
            $parsed = $this->parsePolicy($graphPolicy);
            $policy = SensitivityLabelPolicy::updateOrCreate(
                ['policy_id' => $graphPolicy['id']],
                array_merge($parsed, ['synced_at' => now()])
            );
            $syncedIds[] = $policy->id;
        }

        SensitivityLabelPolicy::whereNotIn('id', $syncedIds)->delete();

        return count($syncedIds);
    }

    public function syncSiteLabels(): int
    {
        $sites = $this->fetchSitesFromGraph();
        $syncedIds = [];

        foreach ($sites as $site) {
            $siteLabel = $this->fetchSiteLabelFromGraph($site['id']);
            if (! $siteLabel) {
                continue;
            }

            $label = SensitivityLabel::where('label_id', $siteLabel)->first();
            if (! $label) {
                continue;
            }

            $sharingCapability = $site['sharingCapability'] ?? 'disabled';
            $externalSharing = in_array($sharingCapability, [
                'ExternalUserSharingOnly', 'ExternalUserAndGuestSharing', 'ExistingExternalUserSharingOnly',
            ]);

            $record = SiteSensitivityLabel::updateOrCreate(
                ['site_id' => $site['id']],
                [
                    'site_name' => $site['displayName'] ?? $site['name'] ?? 'Unknown',
                    'site_url' => $site['webUrl'] ?? '',
                    'sensitivity_label_id' => $label->id,
                    'external_sharing_enabled' => $externalSharing,
                    'synced_at' => now(),
                ]
            );
            $syncedIds[] = $record->id;
        }

        SiteSensitivityLabel::whereNotIn('id', $syncedIds)->delete();

        return count($syncedIds);
    }

    public function buildPartnerMappings(): void
    {
        DB::table('sensitivity_label_partner')->delete();

        $this->buildPolicyMappings();
        $this->buildSiteMappings();
    }

    public function getUncoveredPartners(): Collection
    {
        return PartnerOrganization::whereDoesntHave('sensitivityLabels')->get();
    }

    private function buildPolicyMappings(): void
    {
        $policies = SensitivityLabelPolicy::all();

        foreach ($policies as $policy) {
            $labelIds = $policy->labels ?? [];
            $labels = SensitivityLabel::whereIn('label_id', $labelIds)->get();

            $partners = match ($policy->target_type) {
                'all_users_and_guests', 'all_users' => PartnerOrganization::all(),
                'specific_groups' => $this->partnersInGroups($policy->target_groups ?? []),
                default => collect(),
            };

            foreach ($labels as $label) {
                $pivotData = [];
                foreach ($partners as $partner) {
                    $pivotData[$partner->id] = [
                        'matched_via' => 'label_policy',
                        'policy_name' => $policy->name,
                    ];
                }
                $label->partners()->syncWithoutDetaching($pivotData);
            }
        }
    }

    private function buildSiteMappings(): void
    {
        $sites = SiteSensitivityLabel::where('external_sharing_enabled', true)
            ->whereNotNull('sensitivity_label_id')
            ->get();

        $allPartners = PartnerOrganization::all();

        foreach ($sites as $site) {
            $pivotData = [];
            foreach ($allPartners as $partner) {
                $pivotData[$partner->id] = [
                    'matched_via' => 'site_assignment',
                    'site_name' => $site->site_name,
                ];
            }
            $site->sensitivityLabel->partners()->syncWithoutDetaching($pivotData);
        }
    }

    private function partnersInGroups(array $groupIds): \Illuminate\Support\Collection
    {
        if (empty($groupIds)) {
            return collect();
        }

        return PartnerOrganization::whereHas('guestUsers')->get();
    }

    private function fetchLabelsFromGraph(): array
    {
        $response = $this->graph->get('/security/informationProtection/sensitivityLabels');

        return $this->requireValueKey($response, 'sensitivity labels');
    }

    private function fetchLabelPoliciesFromGraph(): array
    {
        $response = $this->graph->get('/security/informationProtection/sensitivityLabels/policies');

        return $this->requireValueKey($response, 'sensitivity label policies');
    }

    private function fetchSitesFromGraph(): array
    {
        $response = $this->graph->get('/sites', [
            'search' => '*',
            '$select' => 'id,displayName,name,webUrl,sharingCapability',
            '$top' => 999,
        ]);

        return $this->requireValueKey($response, 'sites');
    }

    private function requireValueKey(array $response, string $context): array
    {
        if (! array_key_exists('value', $response)) {
            Log::error("Graph API response missing \"value\" key for {$context}", [
                'response_keys' => array_keys($response),
            ]);
            throw new \RuntimeException("Unexpected Graph API response structure for {$context}");
        }

        return $response['value'];
    }

    private function fetchSiteLabelFromGraph(string $siteId): ?string
    {
        try {
            $response = $this->graph->get("/sites/{$siteId}/sensitivityLabel");

            return $response['sensitivityLabelId'] ?? null;
        } catch (GraphApiException $e) {
            Log::warning("Failed to fetch sensitivity label for site {$siteId}: {$e->getMessage()}");

            return null;
        }
    }

    private function parseLabel(array $graphLabel): array
    {
        $contentFormats = $graphLabel['contentFormats'] ?? [];
        $scope = [];
        $hasFileEmail = ! empty(array_intersect(['file', 'email'], $contentFormats));
        $hasSiteGroup = ! empty(array_intersect(['site', 'group', 'schematizedData'], $contentFormats));

        if ($hasFileEmail) {
            $scope[] = 'files_emails';
        }
        if ($hasSiteGroup) {
            $scope[] = 'sites_groups';
        }

        $protectionType = 'none';
        $protection = $graphLabel['protectionSettings'] ?? null;
        if ($protection) {
            if (! empty($protection['encryptionEnabled'])) {
                $protectionType = 'encryption';
            } elseif (! empty($protection['watermarkEnabled']) || ! empty($protection['contentMarking'])) {
                $protectionType = 'watermark';
            } elseif (! empty($protection['headerEnabled']) || ! empty($protection['footerEnabled'])) {
                $protectionType = 'header_footer';
            }
        }

        return [
            'name' => $graphLabel['name'],
            'description' => $graphLabel['description'] ?? null,
            'color' => $graphLabel['color'] ?? null,
            'tooltip' => $graphLabel['tooltip'] ?? null,
            'scope' => $scope,
            'priority' => $graphLabel['priority'] ?? 0,
            'is_active' => $graphLabel['isActive'] ?? true,
            'protection_type' => $protectionType,
            'raw_json' => $graphLabel,
        ];
    }

    private function parsePolicy(array $graphPolicy): array
    {
        $scopes = $graphPolicy['scopes']['users']['included'] ?? [];
        $targetType = 'all_users';

        if (! empty($scopes['allUsersAndGuests'])) {
            $targetType = 'all_users_and_guests';
        } elseif (! empty($scopes['groups'])) {
            $targetType = 'specific_groups';
        }

        $groups = $scopes['groups'] ?? [];

        $labelIds = [];
        foreach (($graphPolicy['settings']['labels'] ?? []) as $labelSetting) {
            if (! empty($labelSetting['labelId'])) {
                $labelIds[] = $labelSetting['labelId'];
            }
        }

        return [
            'name' => $graphPolicy['name'],
            'target_type' => $targetType,
            'target_groups' => $groups,
            'labels' => $labelIds,
            'raw_json' => $graphPolicy,
        ];
    }
}
