<?php

namespace Database\Factories;

use App\Models\AccessPackageCatalog;
use App\Models\PartnerOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessPackageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'graph_id' => fake()->optional()->uuid(),
            'catalog_id' => AccessPackageCatalog::factory(),
            'partner_organization_id' => PartnerOrganization::factory(),
            'display_name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'duration_days' => fake()->randomElement([30, 60, 90, 180]),
            'approval_required' => true,
            'approver_user_id' => User::factory(),
            'is_active' => true,
            'created_by_user_id' => User::factory(),
        ];
    }
}
