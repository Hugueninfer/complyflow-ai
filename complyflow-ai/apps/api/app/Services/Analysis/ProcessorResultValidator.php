<?php

namespace App\Services\Analysis;

use App\Data\Processor\Contract;
use App\Data\Processor\ProcessorResult;
use App\Models\AnalysisRun;
use App\Models\Document;
use App\Models\RequirementSet;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

final class ProcessorResultValidator
{
    public function validate(AnalysisRun $analysis, ProcessorResult $result): void
    {
        // Reapply the Laravel contract at the persistence boundary, including
        // Unicode excerpts, enums, evidence rules and vector dimensions.
        $raw = [
            'analysis_id' => $result->analysisId,
            'processed_documents' => $result->processedDocuments,
            'findings' => array_map(fn ($finding) => [
                'requirement_id' => $finding->requirementId, 'status' => $finding->status,
                'justification' => $finding->justification, 'confidence' => $finding->confidence,
                'requires_human_review' => $finding->requiresHumanReview, 'search_summary' => $finding->searchSummary,
                'citations' => array_map(fn ($citation) => [
                    'document_id' => $citation->documentId, 'page_number' => $citation->pageNumber,
                    'quote' => $citation->quote, 'start_offset' => $citation->startOffset, 'end_offset' => $citation->endOffset,
                ], $finding->citations),
            ], $result->findings),
        ];
        ProcessorResult::fromArray($raw);
        array_walk_recursive($raw, fn ($value) => Contract::check(! is_string($value) || ! str_contains($value, "\0")));
        Contract::check($analysis->public_id === $result->analysisId);
        $set = RequirementSet::forOrganization($analysis->organization_id)->whereKey($analysis->requirement_set_id)->where('status', 'published')->first();
        $supplier = Supplier::forOrganization($analysis->organization_id)->whereKey($analysis->supplier_id)->first();
        Contract::check($set !== null && $supplier !== null);
        $requirements = $set->requirements()->forOrganization($analysis->organization_id)->get();
        Contract::check($requirements->isNotEmpty());
        $this->sameIds($requirements->pluck('public_id')->all(), array_column($result->findings, 'requirementId'));
        $selected = $analysis->document_ids ?? [];
        Contract::check($selected !== [] && array_is_list($selected));
        $documents = Document::forOrganization($analysis->organization_id)->where('supplier_id', $analysis->supplier_id)
            ->whereIn('public_id', $selected)->get();
        $this->sameIds($selected, $documents->pluck('public_id')->all());
        $this->sameIds($selected, array_column($result->processedDocuments, 'document_id'));
        Contract::check(hash_equals($analysis->document_set_hash, AnalysisFingerprint::make($supplier, $set, $documents->pluck('sha256')->all())));
        $documents = $documents->keyBy('public_id');
        foreach ($result->processedDocuments as $processed) {
            $existing = DB::table('document_pages')->where('document_id', $documents[$processed['document_id']]->id)->get()->keyBy('page_number');
            if ($existing->isNotEmpty()) {
                Contract::check($existing->count() === count($processed['pages']));
                foreach ($processed['pages'] as $page) {
                    $saved = $existing->get($page['page_number']);
                    // Historical citations must continue to point at the same text.
                    Contract::check($saved !== null && $saved->organization_id === $analysis->organization_id && $saved->text === $page['text']);
                }
            }
            foreach ($processed['chunks'] as $chunk) {
                foreach ($chunk['embedding'] as $value) {
                    Contract::check(abs($value) <= 3.4028234663852886e38);
                }
            }
        }
    }

    private function sameIds(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);
        Contract::check($expected === $actual && count(array_unique($actual)) === count($actual));
    }
}
