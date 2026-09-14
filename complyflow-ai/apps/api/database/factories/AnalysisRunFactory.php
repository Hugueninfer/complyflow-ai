<?php

namespace Database\Factories;

use App\Models\AnalysisRun;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AnalysisRunFactory extends Factory
{
    protected $model = AnalysisRun::class;

    public function definition(): array
    {
        return [
            'supplier_id' => SupplierFactory::new(),
            'organization_id' => fn (array $a) => Supplier::findOrFail($a['supplier_id'])->organization_id,
            'requirement_set_id' => fn (array $a) => RequirementSetFactory::new()->create(['organization_id' => $a['organization_id']])->id,
            'status' => 'pending', 'attempts' => 0, 'progress' => 0,
            'document_ids' => [], 'document_set_hash' => hash('sha256', 'empty fixture'), 'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
