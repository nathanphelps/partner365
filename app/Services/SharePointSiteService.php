<?php

namespace App\Services;

use App\Exceptions\GraphApiException;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\SharePointSite;
use App\Models\SharePointSitePermission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class SharePointSiteService
{
    public function __construct(
        private MicrosoftGraphService $graph,
    ) {}

    public function syncSites(): int
    {
        $graphSites = $this->fetchSitesFromGraph();
        $syncedIds = [];

        foreach ($graphSites as $graphSite) {
            $labelId = $this->fetchSiteLabelFromGraph($graphSite['id']);
            $sensitivityLabel = $labelId ? SensitivityLabel::where('label_id', $labelId)->first() : null;

            $site = SharePointSite::updateOrCreate(
                ['site_id' => $graphSite['id']],
                [
                    'display_name' => $graphSite['displayName'] ?? $graphSite['name'] ?? 'Unknown',
                    'url' => $graphSite['webUrl'] ?? '',
                    'description' => $graphSite['description'] ?? null,
                    'sensitivity_label_id' => $sensitivityLabel?->id,
                    'external_sharing_capability' => $graphSite['sharingCapability'] ?? 'Disabled',
                    'storage_used_bytes' => $graphSite['storageUsed'] ?? null,
                    'last_activity_at' => $graphSite['lastModifiedDateTime'] ?? null,
                    'owner_display_name' => $graphSite['createdBy']['user']['displayName'] ?? null,
                    'owner_email' => $graphSite['createdBy']['user']['email'] ?? null,
                    'member_count' => null,
                    'raw_json' => $graphSite,
                    'synced_at' => now(),
                ]
            );

            $syncedIds[] = $site->id;
        }

        SharePointSite::whereNotIn('id', $syncedIds)->delete();

        return count($syncedIds);
    }

    public function syncPermissions(): int
    {
        $sites = SharePointSite::where('external_sharing_capability', '!=', 'Disabled')->get();
        $syncedIds = [];

        foreach ($sites as $site) {
            $graphPermissions = $this->fetchPermissionsFromGraph($site->site_id);

            foreach ($graphPermissions as $graphPerm) {
                $grantedTo = $graphPerm['grantedToV2'] ?? $graphPerm['grantedTo'] ?? null;
                if (! $grantedTo) {
                    continue;
                }

                $email = $grantedTo['spiUser']['email']
                    ?? $grantedTo['user']['email']
                    ?? $grantedTo['spiUser']['loginName']
                    ?? null;

                if (! $email) {
                    continue;
                }

                $guestUser = GuestUser::where('email', $email)
                    ->orWhere('user_principal_name', $email)
                    ->first();

                if (! $guestUser) {
                    continue;
                }

                $roles = $graphPerm['roles'] ?? ['read'];
                $grantedVia = $this->determineGrantedVia($graphPerm);

                foreach ($roles as $role) {
                    $record = SharePointSitePermission::updateOrCreate(
                        [
                            'sharepoint_site_id' => $site->id,
                            'guest_user_id' => $guestUser->id,
                            'role' => $role,
                            'granted_via' => $grantedVia,
                        ]
                    );
                    $syncedIds[] = $record->id;
                }
            }
        }

        SharePointSitePermission::whereNotIn('id', $syncedIds)->delete();

        return count($syncedIds);
    }

    public function getPartnerExposure(PartnerOrganization $partner): Collection
    {
        $guestUserIds = $partner->guestUsers()->pluck('id');

        return SharePointSite::whereHas('permissions', function ($q) use ($guestUserIds) {
            $q->whereIn('guest_user_id', $guestUserIds);
        })->with(['sensitivityLabel', 'permissions' => function ($q) use ($guestUserIds) {
            $q->whereIn('guest_user_id', $guestUserIds)->with('guestUser');
        }])->get();
    }

    private function fetchSitesFromGraph(): array
    {
        $response = $this->graph->get('/sites', [
            'search' => '*',
            '$select' => 'id,displayName,name,webUrl,description,sharingCapability,storageUsed,lastModifiedDateTime,createdBy',
            '$top' => 999,
        ]);

        return $this->requireValueKey($response, 'sites');
    }

    private function fetchPermissionsFromGraph(string $siteId): array
    {
        try {
            $response = $this->graph->get("/sites/{$siteId}/permissions");

            return $response['value'] ?? [];
        } catch (GraphApiException $e) {
            Log::warning("Failed to fetch permissions for site {$siteId}: {$e->getMessage()}");

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

    private function determineGrantedVia(array $graphPerm): string
    {
        if (! empty($graphPerm['link'])) {
            return 'sharing_link';
        }

        if (! empty($graphPerm['inheritedFrom'])) {
            return 'group_membership';
        }

        return 'direct';
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
}
