<?php

namespace Tests\Feature\Review;

use App\Models\AnalysisRun;
use App\Models\RequirementSet;
use App\Support\CurrentOrganization;

class DecisionContextTest extends ReviewTestCase
{
    public function test_findings_expose_safe_context_and_persisted_decision(): void
    {
        $url = '/api/v1/analyses/'.$this->run->public_id.'/findings';
        $this->getJson($url)->assertOk()->assertJsonPath('meta.decision_context.supplier_id', $this->supplier->public_id)
            ->assertJsonPath('meta.decision_context.required_pending', 1)->assertJsonPath('meta.decision_context.is_latest_for_supplier', true)
            ->assertJsonPath('meta.decision_context.is_current_checklist', true)->assertJsonPath('meta.decision_context.decision', null);
        $this->review()->assertCreated();
        $this->getJson($url)->assertJsonPath('meta.decision_context.required_pending', 0);
        $id = $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'final'])->assertCreated()->json('data.id');
        $this->getJson($url)->assertJsonPath('meta.decision_context.decision.id', $id)
            ->assertJsonPath('meta.decision_context.decision.reason', 'Private final reasoning')
            ->assertJsonMissingPath('meta.decision_context.decision.decided_by')->assertJsonMissingPath('meta.decision_context.organization_id');
        $this->actingAs($this->analyst)->getJson($url)->assertOk()->assertJsonMissingPath('meta.decision_context');
        $this->actingAs($this->user($this->organization(), 'reviewer'))->getJson($url)->assertNotFound();
    }

    public function test_context_counts_missing_required_findings_and_identifies_history(): void
    {
        $this->review()->assertCreated();
        app(CurrentOrganization::class)->set($this->organization);
        $this->set->requirements()->create(['code' => 'EXTRA', 'title' => 'Extra', 'category' => 'Compliance', 'weight' => 1, 'position' => 2, 'evaluation_text' => 'Find', 'is_required' => true]);
        AnalysisRun::create(['supplier_id' => $this->supplier->id, 'requirement_set_id' => $this->set->id, 'status' => 'pending', 'idempotency_key' => 'new', 'document_set_hash' => str_repeat('b', 64)]);
        RequirementSet::create(['name' => 'Checklist', 'version' => 2, 'parent_id' => $this->set->id, 'status' => 'published']);
        app(CurrentOrganization::class)->clear();
        $this->getJson('/api/v1/analyses/'.$this->run->public_id.'/findings')->assertOk()
            ->assertJsonPath('meta.decision_context.required_pending', 1)->assertJsonPath('meta.decision_context.is_latest_for_supplier', false)
            ->assertJsonPath('meta.decision_context.is_current_checklist', false);
    }
}
