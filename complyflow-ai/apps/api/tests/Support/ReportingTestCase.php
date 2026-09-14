<?php

namespace Tests\Support;

use App\Models\AnalysisRun;
use App\Models\Organization;
use App\Models\Requirement;
use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Documents\DocumentTestCase;

abstract class ReportingTestCase extends DocumentTestCase
{
    protected RequirementSet $set;

    protected Supplier $right;

    protected function setUp(): void
    {
        parent::setUp();
        $this->set = $this->checklist();
        $this->right = $this->supplier($this->organization);
        $this->actingAs($this->analyst);
    }

    protected function checklist(?Organization $tenant = null, array $attributes = []): RequirementSet
    {
        app(CurrentOrganization::class)->set($tenant ?? $this->organization);
        try {
            return RequirementSet::create($attributes + ['name' => 'Checklist', 'version' => 1, 'status' => 'published']);
        } finally {
            app(CurrentOrganization::class)->clear();
        }
    }

    protected function requirement(string $code = 'CERT', int $position = 1, bool $required = true, ?RequirementSet $set = null): Requirement
    {
        $set ??= $this->set;
        app(CurrentOrganization::class)->set($set->organization_id);
        try {
            return $set->requirements()->create(['code' => $code, 'title' => $code, 'category' => 'Compliance', 'weight' => 1, 'position' => $position, 'evaluation_text' => 'Find evidence', 'is_required' => $required]);
        } finally {
            app(CurrentOrganization::class)->clear();
        }
    }

    protected function runFor(?Supplier $supplier = null, ?RequirementSet $set = null, string $status = 'completed'): AnalysisRun
    {
        $supplier ??= $this->supplier;

        return AnalysisRun::create(['organization_id' => $supplier->organization_id, 'supplier_id' => $supplier->id, 'requirement_set_id' => ($set ?? $this->set)->id, 'status' => $status, 'idempotency_key' => (string) Str::uuid(), 'document_set_hash' => str_repeat('a', 64), 'completed_at' => $status === 'completed' ? now() : null]);
    }

    protected function findingFor(AnalysisRun $run, Requirement $requirement, string $status = 'met'): object
    {
        $id = DB::table('analysis_findings')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $run->organization_id, 'analysis_run_id' => $run->id, 'requirement_id' => $requirement->id, 'status' => $status, 'justification' => 'AI evidence', 'confidence' => 0.8]);

        return DB::table('analysis_findings')->find($id);
    }

    protected function reviewFor(object $finding, string $status, ?Organization $tenant = null): string
    {
        $uuid = (string) Str::uuid();
        DB::table('finding_reviews')->insert(['public_id' => $uuid, 'organization_id' => ($tenant ?? $this->organization)->id, 'analysis_finding_id' => $finding->id, 'reviewer_id' => $this->analyst->id, 'status' => $status, 'justification' => 'Human evidence', 'reviewed_at' => now()]);

        return $uuid;
    }

    protected function comparisonUrl(array $parameters = []): string
    {
        return '/api/v1/comparisons?'.http_build_query($parameters + ['left' => $this->supplier->public_id, 'right' => $this->right->public_id, 'requirement_set' => $this->set->public_id]);
    }

    protected function evidenceFor(AnalysisRun $run, object $finding, int $pages = 1): string
    {
        $documentUuid = (string) Str::uuid();
        $document = DB::table('documents')->insertGetId(['public_id' => $documentUuid, 'organization_id' => $run->organization_id, 'supplier_id' => $run->supplier_id, 'original_name' => 'Private contract.pdf', 'storage_name' => (string) Str::uuid(), 'mime_type' => 'application/pdf', 'size_bytes' => 50, 'sha256' => hash('sha256', $documentUuid)]);
        DB::table('document_blobs')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $run->organization_id, 'document_id' => $document, 'contents' => 'PRIVATE_BINARY_CONTENT']);
        for ($number = $pages; $number >= 1; $number--) {
            $page = DB::table('document_pages')->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $run->organization_id, 'document_id' => $document, 'page_number' => $number, 'text' => 'PRIVATE_FULL_PAGE_TEXT']);
            DB::table('finding_citations')->insert(['public_id' => (string) Str::uuid(), 'organization_id' => $run->organization_id, 'analysis_finding_id' => $finding->id, 'document_id' => $document, 'document_page_id' => $page, 'excerpt' => str_repeat('é', 250), 'start_offset' => 0, 'end_offset' => 250]);
        }
        $run->update(['document_ids' => array_merge($run->document_ids ?? [], [$documentUuid])]);

        return $documentUuid;
    }
}
