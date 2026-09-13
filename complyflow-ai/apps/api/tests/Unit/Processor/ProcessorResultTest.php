<?php

namespace Tests\Unit\Processor;

use App\Data\Processor\ProcessorResult;
use App\Services\Processor\ProcessorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProcessorResultTest extends TestCase
{
    public function test_page_numbers_must_fit_postgresql_integer_even_when_all_references_match(): void
    {
        $raw = self::valid();
        $raw['findings'][0]['citations'][0]['page_number'] = 2147483647;
        $raw['processed_documents'][0]['pages'][0]['page_number'] = 2147483647;
        $raw['processed_documents'][0]['chunks'][0]['page_number'] = 2147483647;
        $this->assertSame(2147483647, ProcessorResult::fromArray($raw)->findings[0]->citations[0]->pageNumber);
        $raw['findings'][0]['citations'][0]['page_number'] = 2147483648;
        $raw['processed_documents'][0]['pages'][0]['page_number'] = 2147483648;
        $raw['processed_documents'][0]['chunks'][0]['page_number'] = 2147483648;
        $this->expectException(ProcessorException::class);
        ProcessorResult::fromArray($raw);
    }

    private static function valid(): array
    {
        return ['analysis_id' => '10000000-0000-4000-8000-000000000001', 'findings' => [[
            'requirement_id' => '20000000-0000-4000-8000-000000000001', 'status' => 'met', 'justification' => 'Found', 'confidence' => 0.8, 'requires_human_review' => true, 'search_summary' => null,
            'citations' => [['document_id' => '30000000-0000-4000-8000-000000000001', 'page_number' => 1, 'quote' => 'ação', 'start_offset' => 0, 'end_offset' => 4]],
        ]], 'processed_documents' => [['document_id' => '30000000-0000-4000-8000-000000000001', 'pages' => [['page_number' => 1, 'text' => 'ação', 'ocr_used' => false]], 'chunks' => [['page_number' => 1, 'index' => 0, 'text' => 'ação', 'start_offset' => 0, 'end_offset' => 4, 'embedding' => array_fill(0, 384, 0)]]]]];
    }

    public function test_accepts_unicode_character_offsets_and_missing_with_search_summary(): void
    {
        $dto = ProcessorResult::fromArray(self::valid());
        $this->assertSame('ação', $dto->findings[0]->citations[0]->quote);
        $raw = self::valid();
        $raw['findings'][0]['status'] = 'missing';
        $raw['findings'][0]['citations'] = [];
        $raw['findings'][0]['search_summary'] = 'Searched all pages.';
        $this->assertSame('missing', ProcessorResult::fromArray($raw)->findings[0]->status);
    }

    public static function invalidResults(): iterable
    {
        foreach ([['extra', 'approve'], ['analysis_id', 'invalid'], ['findings.0.confidence', '0.8'], ['findings.0.confidence', 1.1], ['findings.0.requires_human_review', 1], ['findings.0.status', 'approved'], ['findings.0.justification', ' '], ['findings.0.citations', []], ['findings.0.citations.0.page_number', 2], ['findings.0.citations.0.page_number', '1'], ['findings.0.citations.0.end_offset', 99], ['findings.0.citations.0.quote', 'fake'], ['processed_documents.0.pages.0.ocr_used', 1], ['processed_documents.0.chunks.0.embedding', [0]], ['processed_documents.0.chunks.0.text', 'fake'], ['processed_documents.0.pages', []], ['findings.0.status', 'missing']] as [$path, $value]) {
            $raw = self::valid();
            $target = &$raw;
            $keys = explode('.', $path);
            foreach ($keys as $key) {
                $target = &$target[$key];
            }
            $target = $value;
            unset($target);
            yield $path.'='.json_encode($value) => [$raw];
        }
    }

    #[DataProvider('invalidResults')]
    public function test_rejects_invalid_closed_contract_or_evidence(array $raw): void
    {
        $this->expectException(ProcessorException::class);
        ProcessorResult::fromArray($raw);
    }
}
