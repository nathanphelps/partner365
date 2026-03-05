<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_review_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_review_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('due_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_review_instances');
    }
};
