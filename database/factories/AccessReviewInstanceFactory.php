<?php

namespace Database\Factories;

use App\Enums\ReviewInstanceStatus;
use App\Models\AccessReview;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccessReviewInstanceFactory extends Factory
{
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-30 days', 'now');
        $dueAt = fake()->dateTimeBetween($startedAt, '+30 days');

        return [
            'access_review_id' => AccessReview::factory(),
            'status' => fake()->randomElement(ReviewInstanceStatus::cases()),
            'started_at' => $startedAt,
            'due_at' => $dueAt,
            'completed_at' => null,
        ];
    }
}
