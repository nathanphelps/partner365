<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use App\Services\ConditionalAccessPolicyService;
use Illuminate\Console\Command;

class SyncConditionalAccessPolicies extends Command
{
    protected $signature = 'sync:conditional-access-policies';

    protected $description = 'Sync Conditional Access policies targeting guest/external users from Microsoft Graph API';

    public function handle(ConditionalAccessPolicyService $service): int
    {
        $log = SyncLog::create([
            'type' => 'conditional_access_policies',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Fetching Conditional Access policies from Graph API...');

            $synced = $service->syncPolicies();

            $this->info("Synced {$synced} Conditional Access policies targeting guest/external users.");

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
