<?php

namespace App\Data\Processor;

final readonly class FindingResult
{
    private function __construct(public string $requirementId, public string $status, public string $justification, public float $confidence, public bool $requiresHumanReview, public ?string $searchSummary, public array $citations) {}

    public static function fromArray(mixed $raw): self
    {
        $raw = Contract::object($raw, ['requirement_id', 'status', 'justification', 'confidence', 'requires_human_review', 'search_summary', 'citations']);
        Contract::check(in_array($raw['status'], ['met', 'partial', 'missing', 'inconclusive'], true));
        $confidence = Contract::number($raw['confidence']);
        Contract::check($confidence >= 0 && $confidence <= 1 && $raw['requires_human_review'] === true);
        $summary = $raw['search_summary'] === null ? null : Contract::text($raw['search_summary']);
        $citations = array_map(CitationResult::fromArray(...), Contract::list($raw['citations']));
        Contract::check($raw['status'] === 'missing' ? ($citations === [] && $summary !== null) : $citations !== []);

        return new self(Contract::uuid($raw['requirement_id']), $raw['status'], Contract::text($raw['justification']), $confidence, true, $summary, $citations);
    }
}
