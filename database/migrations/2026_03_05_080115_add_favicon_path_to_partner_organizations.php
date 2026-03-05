<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->string('favicon_path')->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->dropColumn('favicon_path');
        });
    }
};
