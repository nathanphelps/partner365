<?php

namespace Database\Factories;

use App\Models\LabelSweepRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabelSweepRunFactory extends Factory
{
    protected $model = LabelSweepRun::class;

    public function definition(): array
    {
        return [
            'started_at' => now(),
            'status' => 'running',
            'total_scanned' => 0,
            'already_labeled' => 0,
            'applied' => 0,
            'skipped_excluded' => 0,
            'failed' => 0,
        ];
    }
}
