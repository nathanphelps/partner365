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
        Schema::create('guest_users', function (Blueprint $table) {
            $table->id();
            $table->string('entra_user_id')->unique();
            $table->string('email');
            $table->string('display_name')->nullable();
            $table->string('user_principal_name')->nullable();
            $table->foreignId('partner_organization_id')->nullable()->constrained('partner_organizations')->nullOnDelete();
            $table->string('invitation_status')->default('pending_acceptance');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('last_sign_in_at')->nullable();
            $table->boolean('account_enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_users');
    }
};
