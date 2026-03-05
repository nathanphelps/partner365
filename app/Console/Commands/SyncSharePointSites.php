<?php

namespace App\Console\Commands;

use App\Enums\ActivityAction;
use App\Models\SyncLog;
use App\Services\ActivityLogService;
use App\Services\SharePointSiteService;
use Illuminate\Console\Command;

class SyncSharePointSites extends Command
{
    protected $signature = 'sync:sharepoint-sites';

    protected $description = 'Sync SharePoint sites and guest user permissions from Microsoft Graph API';

    public function handle(SharePointSiteService $service): int
    {
        $log = SyncLog::create([
            'type' => 'sharepoint_sites',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Fetching SharePoint sites from Graph API...');
            $sitesSynced = $service->syncSites();
            $this->info("Synced {$sitesSynced} SharePoint sites.");

            $this->info('Syncing site permissions...');
            $permissionsSynced = $service->syncPermissions();
            $this->info("Synced {$permissionsSynced} site permissions.");

            $log->update([
                'status' => 'completed',
                'records_synced' => $sitesSynced + $permissionsSynced,
                'completed_at' => now(),
            ]);

            app(ActivityLogService::class)->logSystem(ActivityAction::SharePointSitesSynced, details: [
                'sites_synced' => $sitesSynced,
                'permissions_synced' => $permissionsSynced,
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
