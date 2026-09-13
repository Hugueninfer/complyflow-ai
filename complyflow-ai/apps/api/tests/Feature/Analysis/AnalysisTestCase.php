<?php

namespace Tests\Feature\Analysis;

use App\Models\Document;
use App\Models\RequirementSet;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Documents\DocumentTestCase;

abstract class AnalysisTestCase extends DocumentTestCase
{
    protected RequirementSet $set;

    protected Document $document;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentOrganization::class)->set($this->organization);
        $this->set = RequirementSet::create(['name' => 'Checklist', 'version' => 1, 'status' => 'published']);
        $this->set->requirements()->create(['code' => 'CERT', 'title' => 'Certidão válida', 'category' => 'Compliance', 'weight' => 1, 'position' => 1, 'evaluation_text' => 'Confirmar evidência.', 'is_required' => true]);
        app(CurrentOrganization::class)->clear();
        $id = $this->actingAs($this->analyst)->postJson($this->url(), ['file' => $this->pdf()])->assertCreated()->json('data.id');
        $this->document = Document::where('public_id', $id)->firstOrFail();
        Queue::fake();
    }

    protected function analysisUrl(): string
    {
        return '/api/v1/suppliers/'.$this->supplier->public_id.'/analyses';
    }

    protected function payload(): array
    {
        return ['requirement_set_id' => $this->set->public_id, 'document_ids' => [$this->document->public_id]];
    }

    protected function start(string $key = 'demo-run-1')
    {
        return $this->postJson($this->analysisUrl(), $this->payload(), ['Idempotency-Key' => $key]);
    }

    protected function processorResult(string $id): array
    {
        return ['analysis_id' => $id, 'findings' => [[
            'requirement_id' => $this->set->requirements()->first()->public_id,
            'status' => 'met', 'justification' => 'Evidência localizada.', 'confidence' => 0.8,
            'requires_human_review' => true, 'search_summary' => null,
            'citations' => [['document_id' => $this->document->public_id, 'page_number' => 1, 'quote' => 'Certidão válida.', 'start_offset' => 0, 'end_offset' => 16]],
        ]], 'processed_documents' => [[
            'document_id' => $this->document->public_id,
            'pages' => [['page_number' => 1, 'text' => 'Certidão válida.', 'ocr_used' => false]],
            'chunks' => [['page_number' => 1, 'index' => 0, 'text' => 'Certidão válida.', 'start_offset' => 0, 'end_offset' => 16, 'embedding' => array_fill(0, 384, 0.0)]],
        ]]];
    }
}
