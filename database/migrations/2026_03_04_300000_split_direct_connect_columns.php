<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->boolean('direct_connect_inbound_enabled')->default(false)->after('direct_connect_enabled');
            $table->boolean('direct_connect_outbound_enabled')->default(false)->after('direct_connect_inbound_enabled');
        });

        DB::table('partner_organizations')
            ->where('direct_connect_enabled', true)
            ->update([
                'direct_connect_inbound_enabled' => true,
                'direct_connect_outbound_enabled' => true,
            ]);

        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->dropColumn('direct_connect_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->boolean('direct_connect_enabled')->default(false)->after('device_trust_enabled');
        });

        DB::table('partner_organizations')
            ->where('direct_connect_inbound_enabled', true)
            ->orWhere('direct_connect_outbound_enabled', true)
            ->update(['direct_connect_enabled' => true]);

        Schema::table('partner_organizations', function (Blueprint $table) {
            $table->dropColumn(['direct_connect_inbound_enabled', 'direct_connect_outbound_enabled']);
        });
    }
};
