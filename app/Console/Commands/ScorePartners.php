<?php

namespace App\Console\Commands;

use App\Models\PartnerOrganization;
use App\Services\TrustScoreService;
use Illuminate\Console\Command;

class ScorePartners extends Command
{
    protected $signature = 'score:partners';

    protected $description = 'Calculate trust scores for all partner organizations';

    public function handle(TrustScoreService $scoreService): int
    {
        $partners = PartnerOrganization::whereNotNull('domain')->get();
        $scored = 0;
        $failed = 0;

        foreach ($partners as $partner) {
            try {
                $result = $scoreService->calculateScore($partner);

                if ($result !== null) {
                    $scoreService->storeScore($partner, $result);
                    $scored++;
                    $this->line("{$partner->display_name}: {$result['score']}/100");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Failed to score {$partner->display_name}: {$e->getMessage()}");
            }
        }

        $this->info("Scored {$scored} partner(s).".($failed > 0 ? " Failed: {$failed}." : ''));

        return Command::SUCCESS;
    }
}
