<?php

namespace Database\Factories;

use App\Models\SyncLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class SyncLogFactory extends Factory
{
    protected $model = SyncLog::class;

    public function definition(): array
    {
        $started = $this->faker->dateTimeBetween('-1 week');

        return [
            'type' => $this->faker->randomElement(['partners', 'guests']),
            'status' => 'completed',
            'records_synced' => $this->faker->numberBetween(0, 50),
            'started_at' => $started,
            'completed_at' => (clone $started)->modify('+' . $this->faker->numberBetween(1, 30) . ' seconds'),
        ];
    }
}
