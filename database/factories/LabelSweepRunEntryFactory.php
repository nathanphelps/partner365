<?php

namespace Database\Factories;

use App\Models\LabelSweepRun;
use App\Models\LabelSweepRunEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabelSweepRunEntryFactory extends Factory
{
    protected $model = LabelSweepRunEntry::class;

    public function definition(): array
    {
        return [
            'label_sweep_run_id' => LabelSweepRun::factory(),
            'site_url' => fake()->url(),
            'site_title' => fake()->words(2, true),
            'action' => 'applied',
            'label_id' => fake()->uuid(),
            'matched_rule_id' => null,
            'error_message' => null,
            'error_code' => null,
            'processed_at' => now(),
        ];
    }
}
