<?php

namespace App\Console\Commands;

use App\Models\PartnerOrganization;
use App\Models\SyncLog;
use App\Services\CrossTenantPolicyService;
use App\Services\TenantResolverService;
use Illuminate\Console\Command;

class SyncPartners extends Command
{
    protected $signature = 'sync:partners';

    protected $description = 'Sync partner organizations from Microsoft Graph API';

    public function handle(CrossTenantPolicyService $policyService, TenantResolverService $resolver): int
    {
        $log = SyncLog::create([
            'type' => 'partners',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
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
                $directConnectInbound = $partner['b2bDirectConnectInbound'] ?? [];
                $directConnectOutbound = $partner['b2bDirectConnectOutbound'] ?? [];
                $tenantRestrictions = $partner['tenantRestrictions'] ?? null;

                PartnerOrganization::updateOrCreate(
                    ['tenant_id' => $tenantId],
                    [
                        'display_name' => $displayName,
                        'domain' => $domain,
                        'mfa_trust_enabled' => $inboundTrust['isMfaAccepted'] ?? false,
                        'device_trust_enabled' => $inboundTrust['isCompliantDeviceAccepted'] ?? false,
                        'b2b_inbound_enabled' => ($b2bInbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
                        'b2b_outbound_enabled' => ($b2bOutbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
                        'direct_connect_inbound_enabled' => ($directConnectInbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
                        'direct_connect_outbound_enabled' => ($directConnectOutbound['usersAndGroups']['accessType'] ?? '') === 'allowed',
                        'tenant_restrictions_enabled' => $tenantRestrictions !== null,
                        'tenant_restrictions_json' => $tenantRestrictions,
                        'raw_policy_json' => $partner,
                        'last_synced_at' => now(),
                    ]
                );

                $synced++;
            }

            $this->info("Synced {$synced} partner organizations.");

            $log->update([
                'status' => 'completed',
                'records_synced' => $synced,
                'completed_at' => now(),
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $this->error("Sync failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
