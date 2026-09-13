<?php

namespace Tests\Feature\Review;

use App\Models\FindingReview;
use App\Models\RequirementSet;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FindingReviewTest extends ReviewTestCase
{
    public function test_cannot_review_a_finding_attached_to_a_different_checklist_requirement(): void
    {
        $foreignOrganization = $this->organization();
        app(CurrentOrganization::class)->set($foreignOrganization);
        $foreignSet = RequirementSet::create(['name' => 'Foreign', 'version' => 1, 'status' => 'published']);
        $foreignRequirement = $foreignSet->requirements()->create(['code' => 'FOREIGN', 'title' => 'Foreign', 'category' => 'Compliance', 'weight' => 1, 'position' => 1, 'evaluation_text' => 'Foreign', 'is_required' => true]);
        app(CurrentOrganization::class)->clear();
        DB::table('analysis_findings')->where('id', $this->finding->id)->update(['requirement_id' => $foreignRequirement->id]);
        $this->review()->assertNotFound();
        $this->assertDatabaseCount('finding_reviews', 0);
    }

    public function test_owner_can_review_and_client_identity_fields_cannot_spoof_the_actor(): void
    {
        $owner = $this->user($this->organization, 'owner');
        $this->actingAs($owner)->postJson($this->reviewUrl(), $this->reviewPayload() + ['reviewer_id' => $this->analyst->public_id, 'organization_id' => $this->organization()->public_id], ['Idempotency-Key' => 'owner'])->assertCreated();
        $this->assertDatabaseHas('finding_reviews', ['reviewer_id' => $owner->id, 'organization_id' => $this->organization->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_public_id' => $owner->public_id]);
    }

    public function test_reviewer_correction_preserves_ai_and_appends_sanitized_audit(): void
    {
        $id = $this->review()->assertCreated()->json('data.id');
        $this->assertDatabaseHas('analysis_findings', ['id' => $this->finding->id, 'status' => 'met', 'justification' => 'AI evidence']);
        $this->assertDatabaseHas('finding_reviews', ['public_id' => $id, 'analysis_finding_id' => $this->finding->id, 'status' => 'partial', 'reviewer_id' => $this->reviewer->id]);
        $log = DB::table('audit_logs')->sole();
        $this->assertSame('finding.reviewed', $log->action);
        $this->assertSame($id, $log->target_public_id);
        $this->assertStringNotContainsString('Private', $log->metadata);
        $this->assertStringNotContainsString($this->reviewer->email, $log->metadata);
    }

    public function test_analyst_and_foreign_reviewer_cannot_review(): void
    {
        $this->actingAs($this->analyst)->review()->assertForbidden();
        $this->actingAs($this->user($this->organization(), 'reviewer'))->review()->assertNotFound();
        $this->assertDatabaseCount('finding_reviews', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_retries_are_idempotent_but_corrections_append_new_records(): void
    {
        $id = $this->review()->assertCreated()->json('data.id');
        $this->review()->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($this->reviewUrl(), ['status' => 'missing', 'justification' => 'Changed'], ['Idempotency-Key' => 'review-1'])->assertConflict();
        $this->postJson($this->reviewUrl(), ['status' => 'missing', 'justification' => 'Changed'], ['Idempotency-Key' => 'review-2'])->assertCreated();
        $this->assertDatabaseCount('finding_reviews', 2);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('finding_reviews', ['public_id' => $id, 'status' => 'partial']);
    }

    public function test_review_requires_valid_human_input_and_completed_analysis(): void
    {
        $this->postJson($this->reviewUrl(), $this->reviewPayload())->assertUnprocessable();
        $this->postJson($this->reviewUrl(), ['status' => 'approved', 'justification' => ''], ['Idempotency-Key' => 'invalid'])->assertUnprocessable();
        $this->run->update(['status' => 'processing']);
        $this->review()->assertConflict();
        $this->assertDatabaseCount('finding_reviews', 0);
    }

    public function test_audit_failure_rolls_back_review(): void
    {
        // Fault injection at the audit boundary; the real review transaction must roll back.
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->review()->assertStatus(500);
        $this->assertDatabaseCount('finding_reviews', 0);
    }

    public function test_review_model_rejects_mutation(): void
    {
        $id = $this->review()->assertCreated()->json('data.id');
        $review = FindingReview::where('public_id', $id)->firstOrFail();
        $this->expectException(\LogicException::class);
        $review->update(['status' => 'met']);
    }
}
