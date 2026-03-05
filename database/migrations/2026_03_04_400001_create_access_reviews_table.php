<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('review_type');
            $table->foreignId('scope_partner_id')->nullable()->constrained('partner_organizations')->nullOnDelete();
            $table->string('recurrence_type');
            $table->unsignedInteger('recurrence_interval_days')->nullable();
            $table->string('remediation_action');
            $table->foreignId('reviewer_user_id')->constrained('users');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('graph_definition_id')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_reviews');
    }
};
