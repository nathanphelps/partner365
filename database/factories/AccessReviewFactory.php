<?php

namespace Database\Factories;

use App\Enums\RecurrenceType;
use App\Enums\RemediationAction;
use App\Enums\ReviewType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'review_type' => fake()->randomElement(ReviewType::cases()),
            'recurrence_type' => fake()->randomElement(RecurrenceType::cases()),
            'recurrence_interval_days' => fake()->randomElement([30, 60, 90, null]),
            'remediation_action' => fake()->randomElement(RemediationAction::cases()),
            'reviewer_user_id' => User::factory(),
            'created_by_user_id' => User::factory(),
            'next_review_at' => fake()->optional()->dateTimeBetween('now', '+90 days'),
        ];
    }
}
