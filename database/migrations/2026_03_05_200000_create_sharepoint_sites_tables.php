<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sharepoint_sites', function (Blueprint $table) {
            $table->id();
            $table->string('site_id')->unique();
            $table->string('display_name');
            $table->string('url');
            $table->text('description')->nullable();
            $table->foreignId('sensitivity_label_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_sharing_capability')->default('Disabled');
            $table->unsignedBigInteger('storage_used_bytes')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->string('owner_display_name')->nullable();
            $table->string('owner_email')->nullable();
            $table->unsignedInteger('member_count')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sharepoint_site_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sharepoint_site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('granted_via');
            $table->timestamps();

            $table->unique(
                ['sharepoint_site_id', 'guest_user_id', 'role', 'granted_via'],
                'sp_site_guest_role_via_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sharepoint_site_permissions');
        Schema::dropIfExists('sharepoint_sites');
    }
};
