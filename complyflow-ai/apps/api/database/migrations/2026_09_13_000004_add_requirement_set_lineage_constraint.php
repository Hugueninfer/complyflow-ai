<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requirement_sets', function (Blueprint $table): void {
            $table->unique(
                ['organization_id', 'parent_id', 'version'],
                'requirement_sets_lineage_version_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('requirement_sets', function (Blueprint $table): void {
            $table->dropUnique('requirement_sets_lineage_version_unique');
        });
    }
};
