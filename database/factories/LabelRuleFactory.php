<?php

namespace Database\Factories;

use App\Models\LabelRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class LabelRuleFactory extends Factory
{
    protected $model = LabelRule::class;

    public function definition(): array
    {
        static $priority = 0;
        $priority++;

        return [
            'prefix' => strtoupper(fake()->unique()->bothify('??#')),
            'label_id' => fake()->uuid(),
            'priority' => $priority,
        ];
    }
}
