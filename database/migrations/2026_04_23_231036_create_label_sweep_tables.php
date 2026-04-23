<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_rules', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 100);
            $table->string('label_id', 50);
            $table->integer('priority');
            $table->timestamps();
            $table->unique('priority');
            $table->index('prefix');
        });

        Schema::create('site_exclusions', function (Blueprint $table) {
            $table->id();
            $table->string('pattern', 500);
            $table->timestamps();
        });

        Schema::create('label_sweep_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_scanned')->default(0);
            $table->integer('already_labeled')->default(0);
            $table->integer('applied')->default(0);
            $table->integer('skipped_excluded')->default(0);
            $table->integer('failed')->default(0);
            $table->string('status', 20)->default('running');
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index('started_at');
            $table->index('status');
        });

        Schema::create('label_sweep_run_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_sweep_run_id')->constrained()->cascadeOnDelete();
            $table->string('site_url', 500);
            $table->string('site_title', 300);
            $table->string('action', 20);
            $table->string('label_id', 50)->nullable();
            $table->foreignId('matched_rule_id')->nullable()->constrained('label_rules')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->string('error_code', 20)->nullable();
            $table->timestamp('processed_at');
            $table->index('label_sweep_run_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_sweep_run_entries');
        Schema::dropIfExists('label_sweep_runs');
        Schema::dropIfExists('site_exclusions');
        Schema::dropIfExists('label_rules');
    }
};
