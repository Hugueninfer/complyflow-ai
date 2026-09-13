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
            'citations' => $this->citations->map(fn ($citation) => [
                'id' => $citation->public_id, 'document_id' => $citation->document_public_id,
                'page_number' => $citation->page_number, 'quote' => $citation->excerpt,
                'start_offset' => $citation->start_offset, 'end_offset' => $citation->end_offset,
            ])->values()->all(),
        ];
    }
}
