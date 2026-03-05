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
        Schema::create('conditional_access_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_id')->unique();
            $table->string('display_name');
            $table->string('state');
            $table->string('guest_or_external_user_types')->nullable();
            $table->string('external_tenant_scope')->default('all');
            $table->json('external_tenant_ids')->nullable();
            $table->string('target_applications')->default('all');
            $table->json('grant_controls')->nullable();
            $table->json('session_controls')->nullable();
            $table->json('raw_policy_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('conditional_access_policy_partner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conditional_access_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_organization_id')->constrained()->cascadeOnDelete();
            $table->string('matched_user_type');
            $table->timestamps();

            $table->unique(
                ['conditional_access_policy_id', 'partner_organization_id', 'matched_user_type'],
                'ca_policy_partner_type_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conditional_access_policy_partner');
        Schema::dropIfExists('conditional_access_policies');
    }
};
