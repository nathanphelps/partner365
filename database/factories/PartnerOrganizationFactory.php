<?php

namespace Database\Factories;

use App\Enums\PartnerCategory;
use App\Models\PartnerOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PartnerOrganizationFactory extends Factory
{
    protected $model = PartnerOrganization::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Str::uuid()->toString(),
            'display_name' => fake()->company(),
            'domain' => fake()->domainName(),
            'category' => fake()->randomElement(PartnerCategory::cases()),
            'b2b_inbound_enabled' => fake()->boolean(),
            'b2b_outbound_enabled' => fake()->boolean(),
            'mfa_trust_enabled' => fake()->boolean(),
            'device_trust_enabled' => false,
            'direct_connect_enabled' => false,
        ];
    }
}
