<?php

namespace Database\Factories;

use App\Enums\AccessPackageResourceType;
use App\Models\AccessPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessPackageResourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'access_package_id' => AccessPackage::factory(),
            'resource_type' => fake()->randomElement(AccessPackageResourceType::cases()),
            'resource_id' => fake()->uuid(),
            'resource_display_name' => fake()->words(2, true),
            'graph_id' => fake()->optional()->uuid(),
        ];
    }
}
