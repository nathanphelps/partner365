<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::where('group', 'graph')
            ->where('key', 'labels_csv_path')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally empty: the value was a local filesystem path that
        // varies per host. Restoring a placeholder would create a broken
        // setting.
    }
};
