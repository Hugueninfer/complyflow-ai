<?php

namespace App\Data\Processor;

final readonly class CitationResult
{
    private function __construct(public string $documentId, public int $pageNumber, public string $quote, public int $startOffset, public int $endOffset) {}

    public static function fromArray(mixed $raw): self
    {
        $raw = Contract::object($raw, ['document_id', 'page_number', 'quote', 'start_offset', 'end_offset']);
        $start = Contract::integer($raw['start_offset']);
        $end = Contract::integer($raw['end_offset']);
        Contract::check($end > $start);

        return new self(Contract::uuid($raw['document_id']), Contract::integer($raw['page_number'], 1, 2147483647), Contract::text($raw['quote']), $start, $end);
    }
}
