<?php

namespace Database\Factories;

use App\Enums\ReviewDecision;
use App\Models\AccessReviewInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessReviewDecisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'access_review_instance_id' => AccessReviewInstance::factory(),
            'subject_type' => 'guest_user',
            'subject_id' => fake()->randomNumber(),
            'decision' => ReviewDecision::Pending,
            'justification' => null,
            'decided_by_user_id' => null,
            'decided_at' => null,
            'remediation_applied' => false,
            'remediation_applied_at' => null,
        ];
    }
}
