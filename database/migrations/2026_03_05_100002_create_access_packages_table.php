<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_packages', function (Blueprint $table) {
            $table->id();
            $table->string('graph_id')->nullable()->unique();
            $table->foreignId('catalog_id')->constrained('access_package_catalogs');
            $table->foreignId('partner_organization_id')->constrained('partner_organizations')->cascadeOnDelete();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_days')->default(90);
            $table->boolean('approval_required')->default(true);
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_packages');
    }
};
