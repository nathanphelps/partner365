<?php

namespace App\Console\Commands;

use App\Models\PartnerOrganization;
use App\Services\FaviconService;
use Illuminate\Console\Command;

class SyncFavicons extends Command
{
    protected $signature = 'sync:favicons {--force : Re-fetch all favicons, not just missing ones}';

    protected $description = 'Fetch and cache favicons for partner organizations';

    public function handle(FaviconService $faviconService): int
    {
        $query = PartnerOrganization::whereNotNull('domain');

        if (! $this->option('force')) {
            $query->whereNull('favicon_path');
        }

        $partners = $query->get();

        if ($partners->isEmpty()) {
            $this->info('No partners need favicon updates.');

            return Command::SUCCESS;
        }

        $this->info("Fetching favicons for {$partners->count()} partner(s)...");

        $fetched = 0;
        foreach ($partners as $partner) {
            $faviconService->fetchForPartner($partner);
            $partner->refresh();

            if ($partner->favicon_path) {
                $fetched++;
                $this->line("  Fetched: {$partner->display_name}");
            } else {
                $this->warn("  Failed:  {$partner->display_name}");
            }
        }

        $this->info("Done. {$fetched}/{$partners->count()} favicons cached.");

        return Command::SUCCESS;
    }
}
