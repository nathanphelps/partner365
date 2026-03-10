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
        private SharePointAdminService $spoAdmin,
    ) {}

    public function syncSites(): int
    {
        $graphSites = $this->fetchSitesFromGraph();
        $sharingMap = $this->fetchSharingCapabilities();
        $syncedIds = [];

        foreach ($graphSites as $graphSite) {
            $labelId = $this->fetchSiteLabelFromGraph($graphSite['id']);
            $sensitivityLabel = $labelId ? SensitivityLabel::where('label_id', $labelId)->first() : null;

            $siteUrl = strtolower(rtrim($graphSite['webUrl'] ?? '', '/'));
            $siteData = $sharingMap[$siteUrl] ?? [];
            $sharingCapability = $siteData['sharingCapability'] ?? 'Disabled';

            $site = SharePointSite::updateOrCreate(
                ['site_id' => $graphSite['id']],
                [
                    'display_name' => $graphSite['displayName'] ?? $graphSite['name'] ?? 'Unknown',
                    'url' => $graphSite['webUrl'] ?? '',
                    'description' => $graphSite['description'] ?? null,
                    'sensitivity_label_id' => $sensitivityLabel?->id,
                    'external_sharing_capability' => $sharingCapability,
                    'sharing_domain_restriction_mode' => $siteData['sharingDomainRestrictionMode'] ?? null,
                    'sharing_allowed_domain_list' => $siteData['sharingAllowedDomainList'] ?? null,
                    'sharing_blocked_domain_list' => $siteData['sharingBlockedDomainList'] ?? null,
                    'default_sharing_link_type' => $siteData['defaultSharingLinkType'] ?? null,
                    'default_link_permission' => $siteData['defaultLinkPermission'] ?? null,
                    'external_user_expiration_days' => $siteData['externalUserExpirationInDays'] ?? null,
                    'override_tenant_expiration_policy' => $siteData['overrideTenantExternalUserExpirationPolicy'] ?? false,
                    'conditional_access_policy' => $siteData['conditionalAccessPolicy'] ?? null,
                    'allow_editing' => $siteData['allowEditing'] ?? true,
                    'limited_access_file_type' => $siteData['limitedAccessFileType'] ?? null,
                    'allow_downloading_non_web_viewable' => $siteData['allowDownloadingNonWebViewableFiles'] ?? true,
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
            '$select' => 'id,displayName,name,webUrl,description,lastModifiedDateTime,createdBy',
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

    public function syncSiteExternalUsers(): int
    {
        if (! config('graph.sync_site_users', true)) {
            return 0;
        }

        $totalMapped = 0;

        SharePointSite::chunkById(100, function ($sites) use (&$totalMapped) {
            foreach ($sites as $site) {
                try {
                    $externalUsers = $this->fetchExternalUsersFromInfoList($site->site_id);

                    foreach ($externalUsers as $externalUser) {
                        if (empty($externalUser['email']) && empty($externalUser['userPrincipalName'])) {
                            continue;
                        }

                        $guest = GuestUser::where(function ($q) use ($externalUser) {
                            $q->where('email', $externalUser['email'])
                                ->orWhere('user_principal_name', $externalUser['userPrincipalName']);
                        })->first();

                        if (! $guest) {
                            continue;
                        }

                        $perm = SharePointSitePermission::firstOrCreate(
                            [
                                'sharepoint_site_id' => $site->id,
                                'guest_user_id' => $guest->id,
                                'role' => 'member',
                                'granted_via' => 'site_access',
                            ]
                        );

                        if ($perm->wasRecentlyCreated) {
                            $totalMapped++;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Failed to fetch User Information List for site {$site->site_id}: {$e->getMessage()}");
                }
            }
        });

        return $totalMapped;
    }

    private function fetchExternalUsersFromInfoList(string $siteId): array
    {
        $externalUsers = [];
        $url = "/sites/{$siteId}/lists/User Information List/items";
        $params = [
            '$expand' => 'fields',
            '$top' => 999,
        ];

        do {
            $response = $this->graph->get($url, $params);
            $items = $response['value'] ?? [];

            foreach ($items as $item) {
                $fields = $item['fields'] ?? [];
                $userName = $fields['UserName'] ?? $fields['Name'] ?? '';

                if (! str_contains($userName, '#EXT#')) {
                    continue;
                }

                $email = $fields['EMail'] ?? $this->extractEmailFromLoginName($userName);

                if ($email) {
                    $externalUsers[] = [
                        'email' => strtolower($email),
                        'userPrincipalName' => $userName,
                        'displayName' => $fields['Title'] ?? '',
                    ];
                }
            }

            // Handle pagination
            $nextLink = $response['@odata.nextLink'] ?? null;
            if ($nextLink) {
                $parsed = parse_url($nextLink);
                parse_str($parsed['query'] ?? '', $params);
                $path = $parsed['path'] ?? $url;
                if (str_starts_with($path, '/v1.0')) {
                    $path = substr($path, 5);
                }
                $url = $path;
            }
        } while ($nextLink && count($externalUsers) < 5000);

        return $externalUsers;
    }

    private function extractEmailFromLoginName(string $loginName): ?string
    {
        // Format: i:0#.f|membership|john_fabrikam.com#EXT#@contoso.onmicrosoft.com
        if (preg_match('/\|([^|]+)#EXT#/', $loginName, $matches)) {
            $encoded = $matches[1];
            // Replace last underscore before domain with @
            $lastUnderscore = strrpos($encoded, '_');
            if ($lastUnderscore !== false) {
                return substr_replace($encoded, '@', $lastUnderscore, 1);
            }
        }

        return null;
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
