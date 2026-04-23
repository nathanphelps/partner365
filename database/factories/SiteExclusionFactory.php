<?php

namespace Database\Factories;

use App\Models\SiteExclusion;
use Illuminate\Database\Eloquent\Factories\Factory;

class SiteExclusionFactory extends Factory
{
    protected $model = SiteExclusion::class;

    public function definition(): array
    {
        return [
            'pattern' => '/sites/'.fake()->unique()->slug(2),
        ];
    }
}
