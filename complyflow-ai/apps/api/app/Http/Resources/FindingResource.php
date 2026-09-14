<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->public_id, 'requirement' => [
            'id' => $this->requirement_public_id, 'code' => $this->code, 'title' => $this->title,
            'category' => $this->category, 'weight' => (float) $this->weight,
            'position' => $this->position, 'is_required' => (bool) $this->is_required,
        ], 'status' => $this->status, 'justification' => $this->justification, 'confidence' => (float) $this->confidence,
            'search_summary' => $this->search_summary, 'requires_human_review' => true,
            'review_locked' => $this->review_locked,
            'latest_review' => $this->latest_review ? [
                'id' => $this->latest_review->public_id, 'finding_id' => $this->public_id,
                'status' => $this->latest_review->status, 'justification' => $this->latest_review->justification,
                'note' => $this->latest_review->notes, 'reviewed_at' => $this->latest_review->reviewed_at->toISOString(),
            ] : null,
            'citations' => $this->citations->map(fn ($citation) => [
                'id' => $citation->public_id, 'document_id' => $citation->document_public_id,
                'page_number' => $citation->page_number, 'quote' => $citation->excerpt,
                'start_offset' => $citation->start_offset, 'end_offset' => $citation->end_offset,
            ])->values()->all(),
        ];
    }
}
