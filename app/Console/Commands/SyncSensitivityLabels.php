<?php

namespace App\Console\Commands;

use App\Enums\ActivityAction;
use App\Models\SyncLog;
use App\Services\ActivityLogService;
use App\Services\SensitivityLabelService;
use Illuminate\Console\Command;

class SyncSensitivityLabels extends Command
{
    protected $signature = 'sync:sensitivity-labels';

    protected $description = 'Sync sensitivity labels, policies, and site assignments from Microsoft Graph API';

    public function handle(SensitivityLabelService $service): int
    {
        $log = SyncLog::create([
            'type' => 'sensitivity_labels',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Fetching sensitivity labels from Graph API...');
            $labelResult = $service->syncLabels();
            $this->info("Synced {$labelResult['labels_synced']} sensitivity labels.");

            $this->info('Fetching label policies...');
            $policiesSynced = $service->syncPolicies();
            $this->info("Synced {$policiesSynced} label policies.");

            $this->info('Fetching site label assignments...');
            $sitesSynced = $service->syncSiteLabels();
            $this->info("Synced {$sitesSynced} site label assignments.");

            $this->info('Building partner mappings...');
            $service->buildPartnerMappings();

            $totalSynced = $labelResult['labels_synced'] + $policiesSynced + $sitesSynced;

            $log->update([
                'status' => 'completed',
                'records_synced' => $totalSynced,
                'completed_at' => now(),
            ]);

            app(ActivityLogService::class)->logSystem(ActivityAction::SensitivityLabelsSynced, details: [
                'labels_synced' => $labelResult['labels_synced'],
                'policies_synced' => $policiesSynced,
                'sites_synced' => $sitesSynced,
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
