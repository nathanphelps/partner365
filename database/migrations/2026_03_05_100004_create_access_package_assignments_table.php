<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_package_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('graph_id')->nullable()->unique();
            $table->foreignId('access_package_id')->constrained('access_packages')->cascadeOnDelete();
            $table->string('target_user_email');
            $table->string('target_user_id')->nullable();
            $table->string('status')->default('pending_approval');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('justification')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_package_assignments');
    }
};
