<?php

namespace Database\Seeders;

use App\Models\SiteExclusion;
use Illuminate\Database\Seeder;

class SensitivitySweepSeeder extends Seeder
{
    public function run(): void
    {
        SiteExclusion::firstOrCreate(['pattern' => '/sites/contentTypeHub']);
    }
}
