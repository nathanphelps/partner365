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
        Schema::create('partner_organizations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->string('display_name');
            $table->string('domain')->nullable();
            $table->string('category')->default('other');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('b2b_inbound_enabled')->default(false);
            $table->boolean('b2b_outbound_enabled')->default(false);
            $table->boolean('mfa_trust_enabled')->default(false);
            $table->boolean('device_trust_enabled')->default(false);
            $table->boolean('direct_connect_enabled')->default(false);
            $table->json('raw_policy_json')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_organizations');
    }
};
