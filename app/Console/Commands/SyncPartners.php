<?php

namespace App\Console\Commands;

use App\Models\PartnerOrganization;
use App\Services\CrossTenantPolicyService;
use App\Services\TenantResolverService;
use Illuminate\Console\Command;

class SyncPartners extends Command
{
    protected $signature = 'sync:partners';
    protected $description = 'Sync partner organizations from Microsoft Graph API';

    public function handle(CrossTenantPolicyService $policyService, TenantResolverService $resolver): int
    {
        $this->info('Fetching partner configurations from Graph API...');

        $partners = $policyService->listPartners();
        $synced = 0;

        foreach ($partners as $partner) {
            $tenantId = $partner['tenantId'];

            $displayName = $tenantId;
            $domain = null;
            try {
                $info = $resolver->resolve($tenantId);
                $displayName = $info['displayName'] ?? $tenantId;
                $domain = $info['defaultDomainName'] ?? null;
            } catch (\Throwable $e) {
                $this->warn("Could not resolve tenant info for {$tenantId}: {$e->getMessage()}");
            }

            $inboundTrust = $partner['inboundTrust'] ?? [];
            $b2bInbound = $partner['b2bCollaborationInbound'] ?? [];
            $b2bOutbound = $partner['b2bCollaborationOutbound'] ?? [];
            $directConnect = $partner['b2bDirectConnectInbound'] ?? [];

            PartnerOrganization::updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'display_name' => $displayName,
                    'domain' => $domain,
                    'mfa_trust_enabled' => $inboundTrust['isMfaAccepted'] ?? false,
                    'device_trust_enabled' => $inboundTrust['isCompliantDeviceAccepted'] ?? false,
                    'b2b_inbound_enabled' => ($b2bInbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
                    'b2b_outbound_enabled' => ($b2bOutbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
                    'direct_connect_enabled' => ($directConnect['usersAndGroups']['accessType'] ?? '') === 'allowed',
                    'raw_policy_json' => $partner,
                    'last_synced_at' => now(),
                ]
            );

            $synced++;
        }

        $this->info("Synced {$synced} partner organizations.");

        return Command::SUCCESS;
    }
}
