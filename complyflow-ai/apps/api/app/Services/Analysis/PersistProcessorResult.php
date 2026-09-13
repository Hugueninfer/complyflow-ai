<?php

namespace App\Services\Analysis;

use App\Data\Processor\ProcessorResult;
use App\Models\AnalysisRun;
use App\Models\Document;
use App\Models\Requirement;
use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Services\Processor\ProcessorException;
use App\Services\Processor\ResultPersister;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PersistProcessorResult implements ResultPersister
{
    public function __construct(private ProcessorResultValidator $validator) {}

    public function handle(AnalysisRun $analysis, ProcessorResult $result): void
    {
        try {
            DB::transaction(function () use ($analysis, $result): void {
                $run = AnalysisRun::whereKey($analysis->id)->where('organization_id', $analysis->organization_id)
                    ->where('status', 'processing')->where('attempts', $analysis->attempts)
                    ->where('owner_message_uuid', $analysis->owner_message_uuid)
                    ->where('owner_reservation_id', $analysis->owner_reservation_id)
                    ->where('owner_reservation_attempt', $analysis->owner_reservation_attempt)->lockForUpdate()->first();
                if (! $run) {
                    return;
                }
                // Match creation's supplier -> checklist -> document lock order.
                // Keep publication and ownership stable through the final commit.
                Supplier::forOrganization($run->organization_id)->whereKey($run->supplier_id)->lockForUpdate()->first();
                RequirementSet::forOrganization($run->organization_id)->whereKey($run->requirement_set_id)->lockForUpdate()->first();
                // Different analyses may share documents. Serialize their extraction writes.
                $documents = Document::forOrganization($run->organization_id)->where('supplier_id', $run->supplier_id)
                    ->whereIn('public_id', $run->document_ids ?? [])->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
                try {
                    $this->validator->validate($run, $result);
                } catch (ProcessorException $error) {
                    $run->update(['status' => 'failed', 'error_code' => $error->publicCode, 'error_message' => $error->getMessage(), 'completed_at' => now()]);

                    return;
                }
                $pages = [];
                foreach ($result->processedDocuments as $processed) {
                    $document = $documents[$processed['document_id']];
                    foreach ($processed['pages'] as $page) {
                        $pageId = DB::table('document_pages')->where('organization_id', $run->organization_id)->where('document_id', $document->id)->where('page_number', $page['page_number'])->value('id');
                        $pageId ??= DB::table('document_pages')->insertGetId($this->identity($run) + [
                            'document_id' => $document->id, 'page_number' => $page['page_number'], 'text' => $page['text'],
                        ]);
                        $pages[$processed['document_id']][$page['page_number']] = $pageId;
                    }
                    foreach ($processed['chunks'] as $chunk) {
                        $attributes = [
                            'document_id' => $document->id, 'document_page_id' => $pages[$processed['document_id']][$chunk['page_number']],
                            'content' => $chunk['text'], 'start_offset' => $chunk['start_offset'], 'end_offset' => $chunk['end_offset'],
                            'embedding' => json_encode($chunk['embedding'], JSON_THROW_ON_ERROR),
                        ];
                        if (! DB::table('document_chunks')->where('organization_id', $run->organization_id)->where($attributes)->exists()) {
                            DB::table('document_chunks')->insert($this->identity($run) + $attributes);
                        }
                    }
                    $document->update(['status' => 'ready']);
                }
                $requirements = Requirement::forOrganization($run->organization_id)->where('requirement_set_id', $run->requirement_set_id)->get()->keyBy('public_id');
                foreach ($result->findings as $finding) {
                    $findingId = DB::table('analysis_findings')->insertGetId($this->identity($run) + [
                        'analysis_run_id' => $run->id, 'requirement_id' => $requirements[$finding->requirementId]->id,
                        'status' => $finding->status, 'justification' => $finding->justification,
                        'confidence' => $finding->confidence, 'search_summary' => $finding->searchSummary,
                    ]);
                    $seen = [];
                    foreach ($finding->citations as $citation) {
                        $key = json_encode([$citation->documentId, $citation->pageNumber, $citation->startOffset, $citation->endOffset]);
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                        DB::table('finding_citations')->insert($this->identity($run) + [
                            'analysis_finding_id' => $findingId, 'document_id' => $documents[$citation->documentId]->id,
                            'document_page_id' => $pages[$citation->documentId][$citation->pageNumber],
                            'excerpt' => $citation->quote, 'start_offset' => $citation->startOffset, 'end_offset' => $citation->endOffset,
                        ]);
                    }
                }
                $run->update(['status' => 'completed', 'progress' => 100, 'error_code' => null, 'error_message' => null, 'completed_at' => now()]);
            });
        } catch (Throwable) {
            // Query exceptions can contain evidence text in their SQL bindings.
            throw new ProcessorException('analysis_failed', true);
        }
    }

    private function identity(AnalysisRun $run): array
    {
        return ['public_id' => (string) Str::uuid(), 'organization_id' => $run->organization_id, 'created_at' => now(), 'updated_at' => now()];
    }
}
