<?php

namespace Tests\Support;

use App\Data\Processor\ProcessorResult;
use App\Models\AnalysisRun;
use App\Models\Requirement;

final class ProcessorResultFactory
{
    public static function payload(AnalysisRun $run): array
    {
        $document = $run->document_ids[0];

        return ['analysis_id' => $run->public_id, 'findings' => Requirement::forOrganization($run->organization_id)->where('requirement_set_id', $run->requirement_set_id)->orderBy('position')->get()->map(fn ($requirement) => [
            'requirement_id' => $requirement->public_id, 'status' => 'met', 'justification' => 'Evidência localizada.', 'confidence' => 0.8,
            'requires_human_review' => true, 'search_summary' => null,
            'citations' => [['document_id' => $document, 'page_number' => 1, 'quote' => 'Certidão válida.', 'start_offset' => 0, 'end_offset' => 16]],
        ])->all(), 'processed_documents' => array_map(fn ($id) => [
            'document_id' => $id, 'pages' => [['page_number' => 1, 'text' => 'Certidão válida. Texto privado fora da citação.', 'ocr_used' => false]],
            'chunks' => [['page_number' => 1, 'index' => 0, 'text' => 'Certidão válida.', 'start_offset' => 0, 'end_offset' => 16, 'embedding' => array_fill(0, 384, 0.0)]],
        ], $run->document_ids)];
    }

    public static function valid(AnalysisRun $run): ProcessorResult
    {
        return ProcessorResult::fromArray(self::payload($run));
    }
}
