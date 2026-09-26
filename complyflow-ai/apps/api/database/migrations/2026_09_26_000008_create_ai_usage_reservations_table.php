<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('usage_date')->index();
            $table->unsignedInteger('units');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_reservations');
    }
};
