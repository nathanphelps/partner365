<?php

namespace App\Services;

use App\Exceptions\GraphApiException;
use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\SensitivityLabelPolicy;
use App\Models\SiteSensitivityLabel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SensitivityLabelService
{
    public function __construct(
        private MicrosoftGraphService $graph,
        private SharePointAdminService $spoAdmin,
        private CompliancePowerShellService $compliance,
    ) {}

    public function syncLabels(): array
    {
        $graphLabels = $this->fetchLabelsWithFallback();

        if ($graphLabels === null) {
            // All tiers failed or unavailable — skip sync, preserve existing labels
            return ['labels_synced' => 0, 'source' => 'unavailable'];
        }

        $syncedLabelIds = [];
        $source = $graphLabels['source'];
        $labels = $graphLabels['labels'];

        // First pass: create/update all labels (without parent references)
        foreach ($labels as $graphLabel) {
            $parsed = $this->parseLabel($graphLabel);
            $label = SensitivityLabel::updateOrCreate(
                ['label_id' => $graphLabel['id']],
                array_merge($parsed, ['synced_at' => now()])
            );
            $syncedLabelIds[] = $label->id;
        }

        // Second pass: link parent-child relationships
        foreach ($labels as $graphLabel) {
            if (! empty($graphLabel['parent']['id'])) {
                $parent = SensitivityLabel::where('label_id', $graphLabel['parent']['id'])->first();
                if ($parent) {
                    SensitivityLabel::where('label_id', $graphLabel['id'])
                        ->update(['parent_label_id' => $parent->id]);
                }
            }
        }

        // Only delete stale labels when we got a definitive list (Tier 1 or 2)
        if (in_array($source, ['graph', 'powershell'])) {
            SensitivityLabel::whereNotIn('id', $syncedLabelIds)->delete();
        }

        return ['labels_synced' => count($syncedLabelIds), 'source' => $source];
    }

    public function syncPolicies(): int
    {
        $graphPolicies = $this->fetchPoliciesWithFallback();

        if ($graphPolicies === null) {
            return 0;
        }

        $syncedIds = [];
        $source = $graphPolicies['source'];
        $policies = $graphPolicies['policies'];

        foreach ($policies as $graphPolicy) {
            $parsed = $this->parsePolicy($graphPolicy);
            $policy = SensitivityLabelPolicy::updateOrCreate(
                ['policy_id' => $graphPolicy['id']],
                array_merge($parsed, ['synced_at' => now()])
            );
            $syncedIds[] = $policy->id;
        }

        if (in_array($source, ['graph', 'powershell'])) {
            SensitivityLabelPolicy::whereNotIn('id', $syncedIds)->delete();
        }

        return count($syncedIds);
    }

    public function syncSiteLabels(): int
    {
        $sites = $this->fetchSitesFromGraph();
        $sharingMap = $this->fetchSharingCapabilities();
        $groupLabelMap = $this->fetchGroupLabelMap();
        $syncedIds = [];

        foreach ($sites as $site) {
            // Try to find the label for this site
            $labelId = null;
            $labelName = null;

            // Source 1: Group label map (most reliable in GCC High)
            if (isset($groupLabelMap[$site['id']])) {
                $labelId = $groupLabelMap[$site['id']]['labelId'];
                $labelName = $groupLabelMap[$site['id']]['labelName'];
            }

            // Source 2: SPO REST per-site call (for non-group sites)
            if (! $labelId) {
                $spoLabel = $this->fetchSiteLabelFromSpoRest($site['webUrl'] ?? '');
                if ($spoLabel) {
                    $labelId = $spoLabel['id'];
                    $labelName = $spoLabel['name'] ?? $labelName;
                }
            }

            // Source 3: Per-site Graph call (last resort)
            if (! $labelId) {
                $labelId = $this->fetchSiteLabelFromGraph($site['id']);
            }

            if (! $labelId) {
                continue;
            }

            // Find or auto-create the label
            $label = SensitivityLabel::where('label_id', $labelId)->first();

            if (! $label) {
                $label = SensitivityLabel::create([
                    'label_id' => $labelId,
                    'name' => $labelName ?? 'Unknown Label',
                    'protection_type' => 'unknown',
                    'synced_at' => now(),
                ]);
            }

            $siteUrl = strtolower(rtrim($site['webUrl'] ?? '', '/'));
            $siteData = $sharingMap[$siteUrl] ?? [];
            $sharingCapability = $siteData['sharingCapability'] ?? 'Disabled';
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

    private function fetchLabelsWithFallback(): ?array
    {
        // Tier 1: Graph API
        try {
            $labels = $this->fetchLabelsFromGraph();

            return ['labels' => $labels, 'source' => 'graph'];
        } catch (GraphApiException|\RuntimeException $e) {
            Log::warning("Graph API label fetch failed: {$e->getMessage()}", [
                'exception_class' => get_class($e),
            ]);
        }

        // Tier 2: PowerShell
        try {
            if ($this->compliance->isAvailable()) {
                $labels = $this->compliance->getLabels();

                return ['labels' => $labels, 'source' => 'powershell'];
            }
        } catch (\RuntimeException $e) {
            Log::warning("PowerShell label fetch failed: {$e->getMessage()}", [
                'exception_class' => get_class($e),
            ]);
        }

        // Tier 3: Stubs will be created during syncSiteLabels()
        Log::warning('No label source available — labels will be created as stubs from site data');

        return null;
    }

    private function fetchPoliciesWithFallback(): ?array
    {
        // Tier 1: Graph API
        try {
            $policies = $this->fetchLabelPoliciesFromGraph();

            return ['policies' => $policies, 'source' => 'graph'];
        } catch (GraphApiException|\RuntimeException $e) {
            Log::warning("Graph API policy fetch failed: {$e->getMessage()}", [
                'exception_class' => get_class($e),
            ]);
        }

        // Tier 2: PowerShell
        try {
            if ($this->compliance->isAvailable()) {
                $policies = $this->compliance->getPolicies();

                return ['policies' => $policies, 'source' => 'powershell'];
            }
        } catch (\RuntimeException $e) {
            Log::warning("PowerShell policy fetch failed: {$e->getMessage()}", [
                'exception_class' => get_class($e),
            ]);
        }

        Log::warning('No policy source available — skipping policy sync');

        return null;
    }

    private function fetchGroupLabelMap(): array
    {
        try {
            $groupsResponse = $this->graph->get('/groups', [
                '$filter' => "groupTypes/any(g:g eq 'Unified')",
                '$select' => 'id,displayName,assignedLabels',
                '$top' => 999,
            ]);

            $groups = $groupsResponse['value'] ?? [];
            $map = []; // siteId → ['labelId' => ..., 'labelName' => ...]

            foreach ($groups as $group) {
                $assignedLabels = $group['assignedLabels'] ?? [];
                if (empty($assignedLabels)) {
                    continue;
                }

                $label = $assignedLabels[0]; // Sites only have one label

                try {
                    $rootSite = $this->graph->get("/groups/{$group['id']}/sites/root", [
                        '$select' => 'id,webUrl',
                    ]);

                    $map[$rootSite['id']] = [
                        'labelId' => $label['labelId'],
                        'labelName' => $label['displayName'] ?? null,
                    ];
                } catch (\Throwable $e) {
                    Log::debug("Could not fetch root site for group {$group['id']}: {$e->getMessage()}");
                }
            }

            return $map;
        } catch (\Throwable $e) {
            Log::warning("Failed to fetch group label map: {$e->getMessage()}");

            return [];
        }
    }

    private function fetchSiteLabelFromSpoRest(string $siteUrl): ?array
    {
        if (empty($siteUrl) || ! $this->spoAdmin->isConfigured()) {
            return null;
        }

        try {
            $token = $this->spoAdmin->getAccessToken();

            $response = Http::withToken($token)
                ->acceptJson()
                ->get("{$siteUrl}/_api/site/SensitivityLabelInfo");

            if ($response->failed()) {
                Log::warning("SPO REST label fetch returned HTTP {$response->status()} for {$siteUrl}", [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $data = $response->json();
            $labelId = $data['Id'] ?? null;

            // Skip empty/zero GUIDs
            if (empty($labelId) || $labelId === '00000000-0000-0000-0000-000000000000') {
                return null;
            }

            return [
                'id' => $labelId,
                'name' => $data['DisplayName'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::debug("SPO REST label fetch failed for {$siteUrl}: {$e->getMessage()}");

            return null;
        }
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
            '$select' => 'id,displayName,name,webUrl',
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

    private function fetchSharingCapabilities(): array
    {
        try {
            if (! $this->spoAdmin->isConfigured()) {
                Log::warning('SharePoint Admin API not configured — sharepoint_tenant is empty. Sharing capabilities will default to Disabled.');

                return [];
            }

            return $this->spoAdmin->getSiteProperties();
        } catch (GraphApiException|\RuntimeException $e) {
            Log::error("Failed to fetch sharing capabilities from SharePoint Admin API — site access controls may be inaccurate: {$e->getMessage()}", [
                'exception_class' => get_class($e),
            ]);

            return [];
        }
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
