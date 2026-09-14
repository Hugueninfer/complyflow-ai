<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ReportingTestCase;

class DashboardTest extends ReportingTestCase
{
    public function test_reviewed_met_replaces_ai_missing_without_letting_foreign_new_runs_hide_it(): void
    {
        $finding = $this->findingFor($this->runFor(), $this->requirement(), 'missing');
        $this->reviewFor($finding, 'partial');
        $this->reviewFor($finding, 'met');
        $foreign = $this->organization();
        $run = $this->runFor($this->supplier($foreign), $this->checklist($foreign));
        $run->update(['supplier_id' => $this->supplier->id]);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertExactJson(['data' => ['suppliers_analyzed' => 1, 'requirements_met' => 1, 'pending_requirements' => 0, 'analyses_awaiting_review' => 0]]);
    }

    public function test_empty_current_checklist_counts_supplier_without_inventing_requirements(): void
    {
        $this->runFor();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertExactJson(['data' => ['suppliers_analyzed' => 1, 'requirements_met' => 0, 'pending_requirements' => 0, 'analyses_awaiting_review' => 0]]);
        $this->set->delete();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.suppliers_analyzed', 0);
    }

    public function test_dashboard_query_count_stays_constant_as_suppliers_and_requirements_grow(): void
    {
        $requirement = $this->requirement();
        $this->findingFor($this->runFor(), $requirement);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/dashboard')->assertOk();
        $initial = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 20; $i++) {
            $this->findingFor($this->runFor($this->supplier($this->organization)), $requirement);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.suppliers_analyzed', 21)->assertJsonPath('data.requirements_met', 21);
        $this->assertSame($initial, count(DB::getQueryLog()));
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));
        foreach (['document_blobs', 'document_pages', 'document_chunks', 'finding_citations', 'justification'] as $unneeded) {
            $this->assertStringNotContainsString($unneeded, $sql);
        }
        DB::disableQueryLog();
    }

    public function test_empty_dashboard_has_stable_zero_counts(): void
    {
        $this->getJson('/api/v1/dashboard')->assertOk()->assertExactJson(['data' => ['suppliers_analyzed' => 0, 'requirements_met' => 0, 'pending_requirements' => 0, 'analyses_awaiting_review' => 0]]);
    }

    public function test_latest_run_and_latest_review_are_counted_once_per_supplier(): void
    {
        $required = $this->requirement();
        $optional = $this->requirement('OPTIONAL', 2, false);
        $old = $this->runFor();
        $this->findingFor($old, $required);
        $current = $this->runFor();
        // Equal timestamps: insertion order, not completion time or UUID, breaks ties.
        $current->update(['completed_at' => now()->subDay()]);
        $finding = $this->findingFor($current, $required);
        $this->reviewFor($finding, 'missing');
        $this->reviewFor($finding, 'partial');
        $this->findingFor($current, $optional);
        $right = $this->runFor($this->right);
        $this->findingFor($right, $required);
        // Missing optional finding is a pending requirement, not a review blocker.
        $this->getJson('/api/v1/dashboard')->assertOk()->assertExactJson(['data' => ['suppliers_analyzed' => 2, 'requirements_met' => 2, 'pending_requirements' => 2, 'analyses_awaiting_review' => 1]]);
    }

    public function test_pending_processing_and_failed_new_runs_hide_historical_completed_runs(): void
    {
        $requirement = $this->requirement();
        foreach (['pending', 'processing', 'failed'] as $status) {
            $supplier = $this->supplier($this->organization);
            $this->findingFor($this->runFor($supplier), $requirement);
            $this->runFor($supplier, status: $status);
        }
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.suppliers_analyzed', 0)->assertJsonPath('data.requirements_met', 0);
    }

    public function test_only_current_published_versions_and_live_suppliers_are_counted(): void
    {
        $requirement = $this->requirement();
        $this->findingFor($this->runFor(), $requirement);
        $draft = $this->checklist(attributes: ['parent_id' => $this->set->id, 'version' => 2, 'status' => 'draft']);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.suppliers_analyzed', 1);
        $draft->update(['status' => 'published']);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.suppliers_analyzed', 0);
        $this->findingFor($this->runFor(set: $draft), $this->requirement(set: $draft));
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.suppliers_analyzed', 1);
        $this->supplier->delete();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.suppliers_analyzed', 0);
    }

    public function test_tenant_filters_apply_to_runs_requirements_and_reviews(): void
    {
        $requirement = $this->requirement();
        $finding = $this->findingFor($this->runFor(), $requirement);
        $foreign = $this->organization();
        $foreignSet = $this->checklist($foreign);
        $foreignRequirement = $this->requirement(set: $foreignSet);
        $this->findingFor($this->runFor($this->supplier($foreign), $foreignSet), $foreignRequirement);
        $this->reviewFor($finding, 'missing', $foreign);
        // A corrupted cross-tenant association must not become an aggregate.
        $this->runFor($this->right, $foreignSet);
        $this->getJson('/api/v1/dashboard?organization_id='.$foreign->public_id)->assertOk()->assertExactJson(['data' => ['suppliers_analyzed' => 1, 'requirements_met' => 1, 'pending_requirements' => 0, 'analyses_awaiting_review' => 1]]);
    }

    public function test_missing_required_findings_block_review_but_decided_analyses_do_not(): void
    {
        $this->requirement();
        $left = $this->runFor();
        $right = $this->runFor($this->right);
        DB::table('supplier_decisions')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'supplier_id' => $this->right->id, 'analysis_run_id' => $right->id, 'decided_by' => $this->analyst->id, 'decision' => 'conditional', 'justification' => 'Explicit human decision', 'decided_at' => now()]);
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.pending_requirements', 2)->assertJsonPath('data.analyses_awaiting_review', 1);
    }

    public function test_dashboard_requires_authentication_and_all_read_permissions(): void
    {
        foreach (['owner', 'reviewer', 'analyst'] as $role) {
            $this->actingAs($this->user($this->organization, $role))->getJson('/api/v1/dashboard')->assertOk();
        }
        foreach (['analysis.view', 'supplier.view', 'requirement.view'] as $permission) {
            $this->seed();
            DB::table('role_permission')->where('role_id', Role::where('name', 'analyst')->value('id'))->where('permission_id', DB::table('permissions')->where('name', $permission)->value('id'))->delete();
            $this->actingAs($this->analyst)->getJson('/api/v1/dashboard')->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }
}
