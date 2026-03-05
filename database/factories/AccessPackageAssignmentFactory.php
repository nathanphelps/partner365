<?php

namespace Database\Factories;

use App\Enums\AssignmentStatus;
use App\Models\AccessPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessPackageAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'graph_id' => fake()->optional()->uuid(),
            'access_package_id' => AccessPackage::factory(),
            'target_user_email' => fake()->safeEmail(),
            'target_user_id' => fake()->optional()->uuid(),
            'status' => fake()->randomElement(AssignmentStatus::cases()),
            'requested_at' => now(),
            'justification' => fake()->optional()->sentence(),
        ];
    }
}
