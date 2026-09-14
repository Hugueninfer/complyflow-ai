<?php

namespace Database\Factories;

use App\Models\AnalysisFinding;
use App\Models\AnalysisRun;
use App\Models\Requirement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AnalysisFindingFactory extends Factory
{
    protected $model = AnalysisFinding::class;

    public function definition(): array
    {
        return [
            'analysis_run_id' => AnalysisRunFactory::new(),
            'organization_id' => fn (array $a) => AnalysisRun::findOrFail($a['analysis_run_id'])->organization_id,
            'requirement_id' => fn (array $a) => Requirement::unguarded(fn () => Requirement::create([
                'organization_id' => $a['organization_id'], 'requirement_set_id' => AnalysisRun::findOrFail($a['analysis_run_id'])->requirement_set_id,
                'code' => 'TEST-'.Str::uuid(), 'title' => 'Requisito fictício', 'category' => 'Demo', 'evaluation_text' => 'Localizar evidência fictícia.', 'weight' => 1, 'position' => 1, 'is_required' => true,
            ])->id),
            'status' => 'missing', 'justification' => 'Nenhuma evidência no exemplo vazio.', 'confidence' => .5, 'search_summary' => 'Fixture sem documentos disponíveis.',
        ];
    }
}
