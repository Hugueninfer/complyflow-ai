<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->jsonb('document_ids')->nullable();
            $table->unique(['organization_id', 'idempotency_key'], 'analysis_runs_tenant_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('analysis_runs', function (Blueprint $table) {
            $table->dropUnique('analysis_runs_tenant_key_unique');
            $table->dropColumn('document_ids');
        });
    }
};
