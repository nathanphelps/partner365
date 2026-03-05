<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_package_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_package_id')->constrained('access_packages')->cascadeOnDelete();
            $table->string('resource_type');
            $table->string('resource_id');
            $table->string('resource_display_name');
            $table->string('graph_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_package_resources');
    }
};
