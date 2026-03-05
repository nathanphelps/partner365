<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AccessPackageCatalogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'graph_id' => fake()->optional()->uuid(),
            'display_name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'is_default' => false,
        ];
    }
}
