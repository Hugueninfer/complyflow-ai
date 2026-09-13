<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->uuid('owner_message_uuid')->nullable();
            $table->string('owner_reservation_id')->nullable();
            $table->unsignedInteger('owner_reservation_attempt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->dropColumn(['owner_message_uuid', 'owner_reservation_id', 'owner_reservation_attempt']);
        });
    }
};
