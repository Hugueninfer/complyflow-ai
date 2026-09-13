<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeLineage();

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

    private function normalizeLineage(): void
    {
        $sets = DB::table('requirement_sets')
            ->get(['id', 'organization_id', 'parent_id'])
            ->keyBy('id');

        foreach ($sets as $set) {
            if ($set->parent_id === null) {
                continue;
            }

            $current = $set;
            $visited = [];

            while ($current->parent_id !== null) {
                if (isset($visited[$current->id])) {
                    throw new RuntimeException('Requirement set lineage contains a cycle.');
                }

                $visited[$current->id] = true;
                $parent = $sets->get($current->parent_id);

                if ($parent === null || $parent->organization_id !== $set->organization_id) {
                    throw new RuntimeException('Requirement set lineage crosses organizations or has a missing parent.');
                }

                $current = $parent;
            }

            if ($set->parent_id !== $current->id) {
                DB::table('requirement_sets')->where('id', $set->id)->update([
                    'parent_id' => $current->id,
                ]);
            }
        }
    }
};
