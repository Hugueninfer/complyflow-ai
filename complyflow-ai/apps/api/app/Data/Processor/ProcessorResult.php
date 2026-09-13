<?php

namespace App\Data\Processor;

final readonly class ProcessorResult
{
    private function __construct(public string $analysisId, public array $findings, public array $processedDocuments) {}

    public static function fromJson(string $json): self
    {
        // Preserve JSON object/array distinctions before associative decoding ({} != []).
        $root = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        Contract::check($root instanceof \stdClass);
        Contract::check(is_array($root->findings ?? null) && is_array($root->processed_documents ?? null));
        foreach ($root->findings as $finding) {
            Contract::check($finding instanceof \stdClass && is_array($finding->citations ?? null));
            foreach ($finding->citations as $citation) {
                Contract::check($citation instanceof \stdClass);
            }
        }
        foreach ($root->processed_documents as $document) {
            Contract::check($document instanceof \stdClass && is_array($document->pages ?? null) && is_array($document->chunks ?? null));
            foreach ($document->pages as $page) {
                Contract::check($page instanceof \stdClass);
            }
            foreach ($document->chunks as $chunk) {
                Contract::check($chunk instanceof \stdClass && is_array($chunk->embedding ?? null));
            }
        }

        return self::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    public static function fromArray(mixed $raw): self
    {
        $raw = Contract::object($raw, ['analysis_id', 'findings', 'processed_documents']);
        $analysisId = Contract::uuid($raw['analysis_id']);
        $findings = array_map(FindingResult::fromArray(...), Contract::list($raw['findings']));
        Contract::check(count(array_unique(array_column($findings, 'requirementId'))) === count($findings));
        $documents = Contract::list($raw['processed_documents']);
        $allPages = [];
        $seen = [];
        foreach ($documents as &$document) {
            $document = Contract::object($document, ['document_id', 'pages', 'chunks']);
            $id = $document['document_id'] = Contract::uuid($document['document_id']);
            Contract::check(! isset($seen[$id]));
            $seen[$id] = true;
            $pages = [];
            foreach (Contract::list($document['pages']) as $page) {
                $page = Contract::object($page, ['page_number', 'text', 'ocr_used']);
                $number = Contract::integer($page['page_number'], 1, 2147483647);
                Contract::check(! isset($pages[$number]) && is_bool($page['ocr_used']));
                $pages[$number] = Contract::text($page['text'], true);
            }
            $indexes = [];
            foreach (Contract::list($document['chunks']) as $chunk) {
                $chunk = Contract::object($chunk, ['page_number', 'index', 'text', 'start_offset', 'end_offset', 'embedding']);
                $number = Contract::integer($chunk['page_number'], 1, 2147483647);
                $index = Contract::integer($chunk['index']);
                Contract::check(isset($pages[$number]) && ! isset($indexes[$index]));
                $indexes[$index] = true;
                Contract::excerpt($pages[$number], Contract::text($chunk['text']), Contract::integer($chunk['start_offset']), Contract::integer($chunk['end_offset']));
                $embedding = Contract::list($chunk['embedding']);
                Contract::check(count($embedding) === 384);
                foreach ($embedding as $value) {
                    Contract::number($value);
                }
            }
            $allPages[$id] = $pages;
        }
        unset($document);
        foreach ($findings as $finding) {
            foreach ($finding->citations as $citation) {
                Contract::check(isset($allPages[$citation->documentId][$citation->pageNumber]));
                Contract::excerpt($allPages[$citation->documentId][$citation->pageNumber], $citation->quote, $citation->startOffset, $citation->endOffset);
            }
        }

        return new self($analysisId, $findings, $documents);
    }
}
