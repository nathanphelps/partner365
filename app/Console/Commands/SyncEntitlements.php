<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use App\Services\EntitlementService;
use Illuminate\Console\Command;

class SyncEntitlements extends Command
{
    protected $signature = 'sync:entitlements';

    protected $description = 'Sync access packages and assignments from Microsoft Graph API';

    public function handle(EntitlementService $entitlementService): int
    {
        $log = SyncLog::create([
            'type' => 'entitlements',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Syncing access packages from Graph API...');
            $packagesSynced = $entitlementService->syncAccessPackages();
            $this->info("Synced {$packagesSynced} access packages.");

            $this->info('Syncing assignments from Graph API...');
            $assignmentsSynced = $entitlementService->syncAssignments();
            $this->info("Synced {$assignmentsSynced} assignments.");

            $log->update([
                'status' => 'completed',
                'records_synced' => $packagesSynced + $assignmentsSynced,
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
