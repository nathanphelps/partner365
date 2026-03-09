<?php

namespace App\Services;

use App\Enums\CloudEnvironment;
use App\Exceptions\GraphApiException;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SharePointAdminService
{
    private const SHARING_MAP = [
        0 => 'Disabled',
        1 => 'ExternalUserSharingOnly',
        2 => 'ExternalUserAndGuestSharing',
        3 => 'ExistingExternalUserSharingOnly',
    ];

    public function isConfigured(): bool
    {
        $tenant = Setting::get('graph', 'sharepoint_tenant', config('graph.sharepoint_tenant'));

        return ! empty($tenant);
    }

    public function getAccessToken(): string
    {
        return Cache::remember('spo_admin_access_token', 3500, function () {
            $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));
            $cloudEnv = CloudEnvironment::tryFrom(
                Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
            ) ?? CloudEnvironment::Commercial;
            $loginUrl = $cloudEnv->loginUrl();

            $tenantSlug = Setting::get('graph', 'sharepoint_tenant', config('graph.sharepoint_tenant'));
            $adminUrl = $cloudEnv->sharePointAdminUrl($tenantSlug);

            $response = Http::asForm()->post(
                "https://{$loginUrl}/{$tenantId}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => Setting::get('graph', 'client_id', config('graph.client_id')),
                    'client_secret' => Setting::get('graph', 'client_secret', config('graph.client_secret')),
                    'scope' => "{$adminUrl}/.default",
                ]
            );

            if ($response->failed()) {
                throw new GraphApiException(
                    'Failed to acquire SharePoint Admin access token: '.($response->json('error_description') ?? 'Unknown error'),
                    $response->status(),
                    $response->json('error') ?? '',
                );
            }

            $token = $response->json('access_token');

            if (empty($token)) {
                throw new GraphApiException(
                    'SharePoint Admin token response did not contain an access_token',
                    $response->status(),
                );
            }

            return $token;
        });
    }

    public function getSiteProperties(): array
    {
        $token = $this->getAccessToken();

        $cloudEnv = CloudEnvironment::tryFrom(
            Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
        ) ?? CloudEnvironment::Commercial;
        $tenantSlug = Setting::get('graph', 'sharepoint_tenant', config('graph.sharepoint_tenant'));
        $adminUrl = $cloudEnv->sharePointAdminUrl($tenantSlug);

        $results = [];
        $startIndex = 0;

        do {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("{$adminUrl}/_api/SPO.Tenant/GetSitePropertiesFromSharePointByFilters", [
                    'filter' => [
                        'IncludePersonalSite' => 'false',
                        'StartIndex' => (string) $startIndex,
                        'IncludeDetail' => 'false',
                    ],
                ]);

            if ($response->failed()) {
                throw new GraphApiException(
                    'SharePoint Admin API call failed: '.($response->json('error.message.value') ?? $response->body()),
                    $response->status(),
                    $response->json('error.code') ?? '',
                );
            }

            $data = $response->json();
            $items = $data['_Child_Items_'] ?? [];

            foreach ($items as $item) {
                $url = rtrim($item['Url'] ?? '', '/');
                $normalizedUrl = strtolower($url);
                $sharingCapability = self::SHARING_MAP[$item['SharingCapability'] ?? 0] ?? 'Disabled';
                $results[$normalizedUrl] = $sharingCapability;
            }

            $startIndex = $data['_nextStartIndex'] ?? -1;
        } while ($startIndex >= 0);

        return $results;
    }
}
