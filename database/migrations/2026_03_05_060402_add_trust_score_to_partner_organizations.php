<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->unsignedTinyInteger('trust_score')->nullable()->after('last_synced_at');
            $table->json('trust_score_breakdown')->nullable()->after('trust_score');
            $table->timestamp('trust_score_calculated_at')->nullable()->after('trust_score_breakdown');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->dropColumn(['trust_score', 'trust_score_breakdown', 'trust_score_calculated_at']);
        });
    }
};
