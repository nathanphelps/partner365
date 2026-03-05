<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->boolean('tenant_restrictions_enabled')->default(false)->after('direct_connect_outbound_enabled');
            $table->json('tenant_restrictions_json')->nullable()->after('tenant_restrictions_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->dropColumn(['tenant_restrictions_enabled', 'tenant_restrictions_json']);
        });
    }
};
