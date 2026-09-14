<?php

namespace Tests\Feature\Comparison;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportingTestCase;

class SupplierComparisonTest extends ReportingTestCase
{
    public function test_same_supplier_cannot_be_compared_using_different_uuid_case(): void
    {
        $this->getJson($this->comparisonUrl(['right' => strtoupper($this->supplier->public_id)]))->assertUnprocessable();
    }

    public function test_empty_checklist_and_missing_findings_do_not_invent_statuses(): void
    {
        $this->getJson($this->comparisonUrl())->assertOk()->assertJsonPath('data.rows', []);
        $run = $this->runFor();
        $this->requirement();
        $this->getJson($this->comparisonUrl())->assertOk()->assertJsonPath('data.left.analysis.id', $run->public_id)
            ->assertJsonPath('data.left.analysis.is_latest_for_supplier', true)->assertJsonPath('data.rows.0.left', null)->assertJsonPath('data.rows.0.right', null);
        $this->set->delete();
        $this->getJson($this->comparisonUrl())->assertNotFound();
    }

    public function test_foreign_findings_and_requirements_cannot_enter_the_matrix(): void
    {
        $requirement = $this->requirement();
        $run = $this->runFor();
        $finding = $this->findingFor($run, $requirement);
        $foreign = $this->organization();
        $foreignSet = $this->checklist($foreign);
        $this->findingFor($run, $this->requirement('FOREIGN', set: $foreignSet), 'missing');
        DB::table('analysis_findings')->where('id', $finding->id)->update(['organization_id' => $foreign->id]);
        $this->getJson($this->comparisonUrl())->assertOk()->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.left', null);
    }

    public function test_evidence_is_bounded_sorted_and_tenant_safe_without_private_data_or_ranking(): void
    {
        $requirement = $this->requirement();
        $left = $this->runFor();
        $finding = $this->findingFor($left, $requirement);
        $document = $this->evidenceFor($left, $finding, 5);
        $right = $this->runFor($this->right);
        $rightFinding = $this->findingFor($right, $requirement);
        $rightDocument = $this->evidenceFor($right, $rightFinding);
        $foreign = $this->organization();
        $foreignSet = $this->checklist($foreign);
        $foreignRun = $this->runFor($this->supplier($foreign), $foreignSet);
        // Hostile rows that pass single-column foreign keys must stay invisible.
        $this->evidenceFor($foreignRun, $finding);
        $this->evidenceFor($right, $finding);
        $outOfSnapshot = $this->evidenceFor($left, $finding);
        $left->update(['document_ids' => [$document]]);
        $this->reviewFor($finding, 'missing', $foreign);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson($this->comparisonUrl())->assertOk()
            ->assertJsonPath('data.rows.0.left.evidence_total', 5)->assertJsonCount(3, 'data.rows.0.left.evidence')
            ->assertJsonPath('data.rows.0.left.evidence.0.document_id', $document)->assertJsonPath('data.rows.0.left.evidence.0.page_number', 1)
            ->assertJsonPath('data.rows.0.left.evidence.0.quote', str_repeat('é', 240))->assertJsonPath('data.rows.0.left.evidence.0.quote_truncated', true)
            ->assertJsonPath('data.rows.0.left.human_review', null)->assertJsonPath('data.rows.0.right.evidence.0.document_id', $rightDocument);
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        foreach (['document_blobs', 'document_chunks', '"p"."text"', '"d".*'] as $privateQuery) {
            $this->assertStringNotContainsString($privateQuery, $sql);
        }
        foreach (['PRIVATE_', $outOfSnapshot, 'organization_id', 'storage_name', 'embedding', 'score', 'ranking', 'recommendation', 'decision', 'analysis_run_id'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertSame(['requirement_set', 'left', 'right', 'rows'], array_keys($response->json('data')));
        $this->assertSame(['finding_id', 'ai', 'human_review', 'requires_human_review', 'evidence', 'evidence_total'], array_keys($response->json('data.rows.0.left')));
        $this->assertDatabaseCount('supplier_decisions', 0);
    }

    public function test_comparison_query_count_does_not_grow_per_requirement_or_finding(): void
    {
        $left = $this->runFor();
        $right = $this->runFor($this->right);
        $requirement = $this->requirement();
        foreach ([$left, $right] as $run) {
            $finding = $this->findingFor($run, $requirement);
            $this->reviewFor($finding, 'met');
            $this->evidenceFor($run, $finding);
        }
        DB::enableQueryLog();
        $this->getJson($this->comparisonUrl())->assertOk()->assertJsonCount(1, 'data.rows');
        $initial = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($index = 2; $index <= 20; $index++) {
            $requirement = $this->requirement('R'.$index, $index);
            foreach ([$left, $right] as $run) {
                $finding = $this->findingFor($run, $requirement);
                $this->reviewFor($finding, 'met');
                $this->evidenceFor($run, $finding);
            }
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($this->comparisonUrl())->assertOk()->assertJsonCount(20, 'data.rows')->assertJsonPath('data.rows.19.right.evidence_total', 1);
        $this->assertSame($initial, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_empty_sides_still_align_all_requirements_in_stable_order(): void
    {
        $this->requirement('Z', 0);
        $a = $this->requirement('A', 0);
        $this->requirement('M', 2);
        $response = $this->getJson($this->comparisonUrl())->assertOk()->assertJsonPath('data.left.analysis', null)->assertJsonPath('data.right.analysis', null)
            ->assertJsonPath('data.rows.0.requirement.id', $a->public_id)->assertJsonPath('data.rows.0.left', null)->assertJsonPath('data.rows.0.right', null);
        $this->assertSame(['A', 'Z', 'M'], array_column(array_column($response->json('data.rows'), 'requirement'), 'code'));
    }

    public function test_latest_completed_exact_version_is_selected_and_review_is_separate_from_ai(): void
    {
        $requirement = $this->requirement();
        $old = $this->runFor();
        $this->findingFor($old, $requirement, 'missing');
        $latest = $this->runFor();
        $latest->update(['completed_at' => now()->subDay()]);
        $finding = $this->findingFor($latest, $requirement);
        $this->reviewFor($finding, 'missing');
        $reviewId = $this->reviewFor($finding, 'partial');
        $this->runFor(status: 'pending');
        $right = $this->runFor($this->right);
        $this->findingFor($right, $requirement, 'inconclusive');
        $other = $this->checklist(attributes: ['name' => 'Other checklist']);
        $this->findingFor($this->runFor($this->right, $other), $this->requirement(set: $other));
        $this->getJson($this->comparisonUrl())->assertOk()->assertJsonPath('data.left.analysis.id', $latest->public_id)
            ->assertJsonPath('data.left.analysis.is_latest_for_supplier', false)->assertJsonPath('data.right.analysis.id', $right->public_id)
            ->assertJsonPath('data.rows.0.left.ai.status', 'met')->assertJsonPath('data.rows.0.left.ai.confidence', 0.8)
            ->assertJsonPath('data.rows.0.left.human_review.id', $reviewId)->assertJsonPath('data.rows.0.left.human_review.status', 'partial')
            ->assertJsonPath('data.rows.0.right.ai.status', 'inconclusive')->assertJsonPath('data.rows.0.right.human_review', null);
        $this->assertDatabaseCount('supplier_decisions', 0);
    }

    public function test_obsolete_version_is_explicit_and_draft_is_not_comparable(): void
    {
        $this->findingFor($this->runFor(), $this->requirement());
        $version = $this->checklist(attributes: ['parent_id' => $this->set->id, 'version' => 2, 'status' => 'draft']);
        $this->getJson($this->comparisonUrl())->assertOk()->assertJsonPath('data.requirement_set.is_current', true);
        $this->getJson($this->comparisonUrl(['requirement_set' => $version->public_id]))->assertConflict();
        $version->update(['status' => 'published']);
        $this->getJson($this->comparisonUrl())->assertOk()->assertJsonPath('data.requirement_set.is_current', false);
        $this->getJson($this->comparisonUrl(['requirement_set' => $version->public_id]))->assertOk()->assertJsonPath('data.left.analysis', null);
    }

    public function test_both_supplier_ids_and_checklist_are_resolved_in_current_tenant(): void
    {
        $this->getJson($this->comparisonUrl())->assertOk();
        $foreign = $this->organization();
        $supplier = $this->supplier($foreign);
        foreach (['left', 'right'] as $side) {
            $this->getJson($this->comparisonUrl([$side => $supplier->public_id]))->assertNotFound();
            $this->getJson($this->comparisonUrl([$side => 'not-a-uuid']))->assertUnprocessable();
        }
        $this->getJson($this->comparisonUrl(['requirement_set' => $this->checklist($foreign)->public_id]))->assertNotFound();
        $this->getJson($this->comparisonUrl(['right' => $this->supplier->public_id]))->assertUnprocessable();
        $this->getJson('/api/v1/comparisons')->assertUnprocessable();
        $this->right->delete();
        $this->getJson($this->comparisonUrl())->assertNotFound();
    }

    public function test_read_permissions_and_authentication_are_required(): void
    {
        foreach (['owner', 'reviewer', 'analyst'] as $role) {
            $this->actingAs($this->user($this->organization, $role))->getJson($this->comparisonUrl())->assertOk();
        }
        foreach (['analysis.view', 'supplier.view', 'requirement.view', 'document.view'] as $permission) {
            $this->seed();
            DB::table('role_permission')->where('role_id', Role::where('name', 'analyst')->value('id'))->where('permission_id', DB::table('permissions')->where('name', $permission)->value('id'))->delete();
            $this->actingAs($this->analyst)->getJson($this->comparisonUrl())->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson($this->comparisonUrl())->assertUnauthorized();
    }
}
