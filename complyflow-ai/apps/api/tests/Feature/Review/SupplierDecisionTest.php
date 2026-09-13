<?php

namespace Tests\Feature\Review;

use App\Models\AnalysisRun;
use App\Models\RequirementSet;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SupplierDecisionTest extends ReviewTestCase
{
    public function test_analyst_cannot_record_final_decision(): void
    {
        $this->actingAs($this->analyst)->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertForbidden();
        $this->assertDatabaseCount('supplier_decisions', 0);
    }

    public function test_decision_requires_explicit_completed_analysis_and_all_required_reviews(): void
    {
        $this->postJson($this->decisionUrl(), ['decision' => 'approved', 'reason' => 'Reviewed'], ['Idempotency-Key' => 'decision'])->assertUnprocessable();
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertConflict();
        $this->review()->assertCreated();
        $this->run->update(['status' => 'pending']);
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertConflict();
        $this->assertDatabaseCount('supplier_decisions', 0);
    }

    public function test_decision_cannot_ignore_a_required_requirement_without_a_finding(): void
    {
        $this->review()->assertCreated();
        app(CurrentOrganization::class)->set($this->organization);
        $this->set->requirements()->create(['code' => 'EXTRA', 'title' => 'Extra', 'category' => 'Compliance', 'weight' => 1, 'position' => 2, 'evaluation_text' => 'Find', 'is_required' => true]);
        app(CurrentOrganization::class)->clear();
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertConflict();
    }

    public function test_human_decision_is_bound_to_analysis_and_checklist_and_retries_do_not_duplicate(): void
    {
        $this->review()->assertCreated();
        $id = $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertCreated()
            ->assertJsonPath('data.analysis_id', $this->run->public_id)->assertJsonPath('data.requirement_set_id', $this->set->public_id)->json('data.id');
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'different'])->assertConflict();
        $this->postJson($this->decisionUrl(), array_replace($this->decisionPayload(), ['decision' => 'rejected']), ['Idempotency-Key' => 'decision'])->assertConflict();
        $this->review('after-decision')->assertConflict();
        $this->assertDatabaseHas('supplier_decisions', ['public_id' => $id, 'analysis_run_id' => $this->run->id, 'decided_by' => $this->reviewer->id, 'decision' => 'conditional']);
        $this->assertDatabaseCount('supplier_decisions', 1);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertStringNotContainsString('Private', DB::table('audit_logs')->where('action', 'supplier.decided')->value('metadata'));
    }

    public function test_cross_tenant_and_other_supplier_analysis_are_not_found(): void
    {
        $this->actingAs($this->user($this->organization(), 'reviewer'))->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertNotFound();
        $this->actingAs($this->reviewer);
        $other = $this->supplier($this->organization);
        $this->postJson('/api/v1/suppliers/'.$other->public_id.'/decisions', $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertNotFound();
    }

    public function test_newer_analysis_or_published_checklist_prevents_stale_decision(): void
    {
        $this->review()->assertCreated();
        app(CurrentOrganization::class)->set($this->organization);
        $new = AnalysisRun::create(['supplier_id' => $this->supplier->id, 'requirement_set_id' => $this->set->id, 'status' => 'pending', 'idempotency_key' => 'new', 'document_set_hash' => str_repeat('b', 64)]);
        app(CurrentOrganization::class)->clear();
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertConflict();
        $new->delete();
        app(CurrentOrganization::class)->set($this->organization);
        RequirementSet::create(['name' => 'Checklist', 'version' => 2, 'parent_id' => $this->set->id, 'status' => 'published']);
        app(CurrentOrganization::class)->clear();
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertConflict();
    }

    public function test_audit_failure_rolls_back_final_decision(): void
    {
        $this->review()->assertCreated();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertStatus(500);
        $this->assertDatabaseCount('supplier_decisions', 0);
        $this->assertDatabaseCount('audit_logs', 1);
    }
}
