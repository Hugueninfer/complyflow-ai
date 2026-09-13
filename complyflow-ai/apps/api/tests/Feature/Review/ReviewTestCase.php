<?php

namespace Tests\Feature\Review;

use App\Models\AnalysisRun;
use App\Models\RequirementSet;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Documents\DocumentTestCase;

abstract class ReviewTestCase extends DocumentTestCase
{
    protected User $reviewer;

    protected AnalysisRun $run;

    protected RequirementSet $set;

    protected object $finding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reviewer = $this->user($this->organization, 'reviewer');
        app(CurrentOrganization::class)->set($this->organization);
        $this->set = RequirementSet::create(['name' => 'Checklist', 'version' => 1, 'status' => 'published']);
        $requirement = $this->set->requirements()->create(['code' => 'CERT', 'title' => 'Certificate', 'category' => 'Compliance', 'weight' => 1, 'position' => 1, 'evaluation_text' => 'Find evidence', 'is_required' => true]);
        $this->run = AnalysisRun::create(['supplier_id' => $this->supplier->id, 'requirement_set_id' => $this->set->id, 'status' => 'completed', 'idempotency_key' => 'fixture', 'document_set_hash' => str_repeat('a', 64), 'completed_at' => now()]);
        $id = DB::table('analysis_findings')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'analysis_run_id' => $this->run->id, 'requirement_id' => $requirement->id, 'status' => 'met', 'justification' => 'AI evidence', 'confidence' => 0.8]);
        $this->finding = DB::table('analysis_findings')->find($id);
        app(CurrentOrganization::class)->clear();
        $this->actingAs($this->reviewer);
    }

    protected function reviewUrl(): string
    {
        return '/api/v1/findings/'.$this->finding->public_id.'/reviews';
    }

    protected function decisionUrl(): string
    {
        return '/api/v1/suppliers/'.$this->supplier->public_id.'/decisions';
    }

    protected function reviewPayload(): array
    {
        return ['status' => 'partial', 'justification' => 'Private human reasoning', 'note' => 'Private manual note'];
    }

    protected function decisionPayload(): array
    {
        return ['analysis_id' => $this->run->public_id, 'decision' => 'conditional', 'reason' => 'Private final reasoning'];
    }

    protected function review(string $key = 'review-1')
    {
        return $this->postJson($this->reviewUrl(), $this->reviewPayload(), ['Idempotency-Key' => $key]);
    }
}
