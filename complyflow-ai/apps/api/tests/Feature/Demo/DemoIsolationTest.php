<?php

namespace Tests\Feature\Demo;

use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_two_demo_visitors_receive_distinct_organizations(): void
    {
        $first = $this->postJson('/api/v1/demo-sessions')->assertCreated()->json('data.organization_id');
        $this->postJson('/api/v1/logout')->assertNoContent();
        $second = $this->postJson('/api/v1/demo-sessions')->assertCreated()->json('data.organization_id');

        $this->assertNotSame($first, $second);
    }

    public function test_demo_clones_only_the_reserved_template_selected_on_the_server(): void
    {
        $template = $this->organization('Reserved demo portfolio', 'demo-template');
        $untrusted = $this->organization('Untrusted source', 'untrusted-source');
        $this->supplier($template, 'Template Supplier');
        $this->supplier($untrusted, 'Untrusted Supplier');
        $this->requirement($template);

        $response = $this->postJson('/api/v1/demo-sessions', [
            'organization_id' => $untrusted->id,
            'template_id' => $untrusted->public_id,
        ])->assertCreated();

        $demo = Organization::query()->where('public_id', $response->json('data.organization_id'))->firstOrFail();

        $this->assertNotSame($template->id, $demo->id);
        $this->assertNotSame($untrusted->id, $demo->id);
        $this->assertSame(['Template Supplier'], DB::table('suppliers')
            ->where('organization_id', $demo->id)
            ->pluck('name')
            ->all());
        $this->assertSame(['DEMO-001'], DB::table('requirements')
            ->where('organization_id', $demo->id)
            ->pluck('code')
            ->all());
    }

    public function test_demo_creation_succeeds_with_empty_data_when_template_is_absent(): void
    {
        $response = $this->postJson('/api/v1/demo-sessions')->assertCreated();
        $demo = Organization::query()->where('public_id', $response->json('data.organization_id'))->firstOrFail();

        $this->assertDatabaseHas('demo_sessions', ['organization_id' => $demo->id]);
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertAuthenticated('web');
    }

    public function test_demo_clones_the_portfolio_graph_without_template_foreign_keys(): void
    {
        $template = $this->organization('Reserved demo portfolio', 'demo-template');
        $templateActor = User::factory()->create();
        $this->seedPortfolioGraph($template, $templateActor);

        $response = $this->postJson('/api/v1/demo-sessions')->assertCreated();
        $demo = Organization::query()->where('public_id', $response->json('data.organization_id'))->firstOrFail();
        $demoUserId = DB::table('demo_sessions')->where('organization_id', $demo->id)->value('user_id');

        foreach ([
            'suppliers', 'requirement_sets', 'requirements', 'documents', 'document_blobs',
            'document_pages', 'document_chunks', 'analysis_runs', 'analysis_findings',
            'finding_citations', 'finding_reviews', 'supplier_decisions',
        ] as $table) {
            $this->assertSame(1, DB::table($table)->where('organization_id', $demo->id)->count(), $table);
            $this->assertSame(1, DB::table($table)->where('organization_id', $template->id)->count(), $table);
        }

        $this->assertSame($demoUserId, DB::table('finding_reviews')
            ->where('organization_id', $demo->id)
            ->value('reviewer_id'));
        $this->assertSame($demoUserId, DB::table('supplier_decisions')
            ->where('organization_id', $demo->id)
            ->value('decided_by'));
        $this->assertSame(1, DB::table('finding_citations')
            ->join('analysis_findings', 'analysis_findings.id', '=', 'finding_citations.analysis_finding_id')
            ->where('finding_citations.organization_id', $demo->id)
            ->where('analysis_findings.organization_id', $demo->id)
            ->count());
    }

    private function organization(string $name, string $slug): Organization
    {
        return Organization::query()->create(compact('name', 'slug'));
    }

    private function supplier(Organization $organization, string $name): Supplier
    {
        app(CurrentOrganization::class)->set($organization);

        try {
            return Supplier::query()->create([
                'name' => $name,
                'risk_level' => 'medium',
            ]);
        } finally {
            app(CurrentOrganization::class)->clear();
        }
    }

    private function requirement(Organization $organization): void
    {
        $requirementSetId = DB::table('requirement_sets')->insertGetId([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'name' => 'Demo controls',
            'version' => 1,
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('requirements')->insert([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'requirement_set_id' => $requirementSetId,
            'code' => 'DEMO-001',
            'title' => 'Documented policy',
            'category' => 'governance',
            'weight' => 1,
            'position' => 1,
            'evaluation_text' => 'The policy must be documented.',
            'is_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPortfolioGraph(Organization $organization, User $actor): void
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'name' => 'Template Supplier',
            'risk_level' => 'high',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requirementSetId = DB::table('requirement_sets')->insertGetId([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'name' => 'Demo controls',
            'version' => 1,
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requirementId = DB::table('requirements')->insertGetId([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'requirement_set_id' => $requirementSetId,
            'code' => 'DEMO-001',
            'title' => 'Documented policy',
            'category' => 'governance',
            'weight' => 1,
            'position' => 1,
            'evaluation_text' => 'The policy must be documented.',
            'is_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $documentId = DB::table('documents')->insertGetId([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'supplier_id' => $supplierId,
            'original_name' => 'policy.txt',
            'storage_name' => Str::uuid().'.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 6,
            'sha256' => hash('sha256', 'policy'),
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('document_blobs')->insert([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'document_id' => $documentId,
            'contents' => 'policy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pageId = DB::table('document_pages')->insertGetId([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'document_id' => $documentId,
            'page_number' => 1,
            'text' => 'policy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('document_chunks')->insert([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'document_id' => $documentId,
            'document_page_id' => $pageId,
            'content' => 'policy',
            'start_offset' => 0,
            'end_offset' => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $analysisRunId = DB::table('analysis_runs')->insertGetId([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'supplier_id' => $supplierId,
            'requirement_set_id' => $requirementSetId,
            'status' => 'completed',
            'attempts' => 1,
            'idempotency_key' => 'template-analysis',
            'document_set_hash' => hash('sha256', 'document-set'),
            'progress' => 100,
            'started_at' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $findingId = DB::table('analysis_findings')->insertGetId([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'analysis_run_id' => $analysisRunId,
            'requirement_id' => $requirementId,
            'status' => 'met',
            'justification' => 'The policy is present.',
            'confidence' => 0.95,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('finding_citations')->insert([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'analysis_finding_id' => $findingId,
            'document_id' => $documentId,
            'document_page_id' => $pageId,
            'excerpt' => 'policy',
            'start_offset' => 0,
            'end_offset' => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('finding_reviews')->insert([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'analysis_finding_id' => $findingId,
            'reviewer_id' => $actor->id,
            'status' => 'met',
            'justification' => 'Reviewed.',
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplier_decisions')->insert([
            'public_id' => Str::uuid(),
            'organization_id' => $organization->id,
            'supplier_id' => $supplierId,
            'analysis_run_id' => $analysisRunId,
            'decided_by' => $actor->id,
            'decision' => 'approved',
            'justification' => 'Approved.',
            'decided_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
