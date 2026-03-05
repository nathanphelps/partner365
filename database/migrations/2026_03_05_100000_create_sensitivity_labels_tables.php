<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensitivity_labels', function (Blueprint $table) {
            $table->id();
            $table->string('label_id')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color')->nullable();
            $table->string('tooltip')->nullable();
            $table->json('scope')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('parent_label_id')->nullable()->constrained('sensitivity_labels')->nullOnDelete();
            $table->string('protection_type')->default('none');
            $table->json('raw_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sensitivity_label_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_id')->unique();
            $table->string('name');
            $table->string('target_type')->default('all_users');
            $table->json('target_groups')->nullable();
            $table->json('labels')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sensitivity_label_partner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sensitivity_label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_organization_id')->constrained()->cascadeOnDelete();
            $table->string('matched_via');
            $table->string('policy_name')->nullable();
            $table->string('site_name')->nullable();
            $table->timestamps();

            $table->unique(
                ['sensitivity_label_id', 'partner_organization_id', 'matched_via', 'policy_name', 'site_name'],
                'sl_partner_match_unique'
            );
        });

        Schema::create('site_sensitivity_labels', function (Blueprint $table) {
            $table->id();
            $table->string('site_id')->unique();
            $table->string('site_name');
            $table->string('site_url');
            $table->foreignId('sensitivity_label_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('external_sharing_enabled')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_sensitivity_labels');
        Schema::dropIfExists('sensitivity_label_partner');
        Schema::dropIfExists('sensitivity_label_policies');
        Schema::dropIfExists('sensitivity_labels');
    }
};
