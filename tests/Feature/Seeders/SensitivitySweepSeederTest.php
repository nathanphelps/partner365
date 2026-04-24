<?php

use App\Models\SiteExclusion;
use Database\Seeders\SensitivitySweepSeeder;

test('seeder inserts /sites/contentTypeHub exclusion when missing', function () {
    $this->seed(SensitivitySweepSeeder::class);

    expect(SiteExclusion::where('pattern', '/sites/contentTypeHub')->count())->toBe(1);
});

test('seeder is idempotent (re-running does not duplicate)', function () {
    $this->seed(SensitivitySweepSeeder::class);
    $this->seed(SensitivitySweepSeeder::class);

    expect(SiteExclusion::where('pattern', '/sites/contentTypeHub')->count())->toBe(1);
});
