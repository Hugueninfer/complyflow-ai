<?php

namespace Tests\Feature\Analysis;

use App\Data\Processor\CitationResult;
use App\Data\Processor\FindingResult;
use App\Data\Processor\ProcessorResult;
use App\Models\AnalysisRun;
use App\Services\Analysis\ProcessorResultValidator;
use App\Services\Processor\ProcessorException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Support\ProcessorResultFactory;

class ProcessorResultValidatorTest extends AnalysisTestCase
{
    public static function invalidEvidence(): array
    {
        return [
            'nonexistent page' => ['findings.0.citations.0.page_number', 99],
            'wrong quote' => ['findings.0.citations.0.quote', 'Invented evidence'],
            'byte offset for unicode' => ['findings.0.citations.0.end_offset', 18],
            'negative offset' => ['findings.0.citations.0.start_offset', -1],
            'invalid enum' => ['findings.0.status', 'approved'],
            'negative confidence' => ['findings.0.confidence', -0.1],
            'confidence over one' => ['findings.0.confidence', 1.1],
            'nonfinite confidence' => ['findings.0.confidence', NAN],
            'no human review' => ['findings.0.requires_human_review', false],
            'met without evidence' => ['findings.0.citations', []],
            'missing with citations' => ['findings.0.status', 'missing'],
            'invalid dimension' => ['processed_documents.0.chunks.0.embedding', [0.0]],
            'nonfinite vector' => ['processed_documents.0.chunks.0.embedding.0', INF],
            'wrong chunk excerpt' => ['processed_documents.0.chunks.0.text', 'invented'],
            'chunk page absent' => ['processed_documents.0.chunks.0.page_number', 99],
            'invalid OCR flag' => ['processed_documents.0.pages.0.ocr_used', 'false'],
        ];
    }

    #[DataProvider('invalidEvidence')]
    public function test_persistence_boundary_revalidates_dto_without_trusting_its_origin(string $path, mixed $value): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $raw = ProcessorResultFactory::payload($run);
        data_set($raw, $path, $value);
        // Deliberately bypass constructors, as unserialization can: the boundary
        // must reject invalid data even if it arrives in a typed DTO container.
        $finding = $raw['findings'][0];
        $citations = array_map(fn ($citation) => $this->unchecked(CitationResult::class, [
            'documentId' => $citation['document_id'], 'pageNumber' => $citation['page_number'], 'quote' => $citation['quote'],
            'startOffset' => $citation['start_offset'], 'endOffset' => $citation['end_offset'],
        ]), $finding['citations']);
        $dto = $this->unchecked(ProcessorResult::class, [
            'analysisId' => $run->public_id, 'processedDocuments' => $raw['processed_documents'],
            'findings' => [$this->unchecked(FindingResult::class, [
                'requirementId' => $finding['requirement_id'], 'status' => $finding['status'], 'justification' => $finding['justification'],
                'confidence' => $finding['confidence'], 'requiresHumanReview' => $finding['requires_human_review'],
                'searchSummary' => $finding['search_summary'], 'citations' => $citations,
            ])],
        ]);
        $this->expectException(ProcessorException::class);
        app(ProcessorResultValidator::class)->validate($run, $dto);
    }

    private function unchecked(string $class, array $properties): object
    {
        $reflection = new ReflectionClass($class);
        $object = $reflection->newInstanceWithoutConstructor();
        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($object, $value);
        }

        return $object;
    }

    public static function invalidContexts(): array
    {
        return array_map(fn ($case) => [$case], ['analysis', 'requirement', 'omitted requirement', 'document', 'extra document', 'tenant document', 'supplier document', 'tenant requirement', 'version', 'deleted document']);
    }

    #[DataProvider('invalidContexts')]
    public function test_database_relationships_and_exact_sets_are_revalidated(string $case): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $raw = ProcessorResultFactory::payload($run);
        if ($case === 'analysis') {
            $raw['analysis_id'] = (string) Str::uuid();
        }
        if ($case === 'requirement') {
            $raw['findings'][0]['requirement_id'] = (string) Str::uuid();
        }
        if ($case === 'omitted requirement') {
            $raw['findings'] = [];
        }
        if ($case === 'document') {
            $foreign = (string) Str::uuid();
            $raw['processed_documents'][0]['document_id'] = $foreign;
            $raw['findings'][0]['citations'][0]['document_id'] = $foreign;
        }
        if ($case === 'extra document') {
            $other = $raw['processed_documents'][0];
            $other['document_id'] = (string) Str::uuid();
            $raw['processed_documents'][] = $other;
        }
        if ($case === 'tenant document') {
            DB::table('documents')->where('id', $this->document->id)->update(['organization_id' => $this->organization()->id]);
        }
        if ($case === 'supplier document') {
            $this->document->update(['supplier_id' => $this->supplier($this->organization)->id]);
        }
        if ($case === 'tenant requirement') {
            DB::table('requirements')->where('requirement_set_id', $this->set->id)->update(['organization_id' => $this->organization()->id]);
        }
        if ($case === 'version') {
            $this->set->update(['version' => 2]);
        }
        if ($case === 'deleted document') {
            $this->document->delete();
        }
        $this->expectException(ProcessorException::class);
        app(ProcessorResultValidator::class)->validate($run, ProcessorResult::fromArray($raw));
    }
}
