<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_review_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_review_instance_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('decision')->default('pending');
            $table->text('justification')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->boolean('remediation_applied')->default(false);
            $table->timestamp('remediation_applied_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_review_decisions');
    }
};
