<?php

namespace Database\Factories;

use App\Models\PartnerTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class PartnerTemplateFactory extends Factory
{
    protected $model = PartnerTemplate::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'policy_config' => [
                'b2b_inbound_enabled' => true,
                'b2b_outbound_enabled' => true,
                'mfa_trust_enabled' => true,
                'device_trust_enabled' => false,
                'direct_connect_inbound_enabled' => false,
                'direct_connect_outbound_enabled' => false,
            ],
        ];
    }
}
