<?php

namespace Tests\Feature\Demo;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Services\Audit\AuditEvent;
use App\Services\Audit\AuditHash;
use App\Services\Audit\AuditLogger;
use App\Services\Demo\CreateDemoSession;
use App\Support\CurrentOrganization;
use Database\Factories\AnalysisFindingFactory;
use Database\Factories\DemoSessionFactory;
use Database\Seeders\DemoTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_is_idempotent_and_stores_real_fictional_pdf_evidence(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoTemplateSeeder::class);
        $before = $this->snapshot();
        $this->seed(DemoTemplateSeeder::class);
        $this->assertSame($before, $this->snapshot());
        $tenant = Organization::where('slug', 'demo-template')->sole();
        $this->assertSame('Atlas Industrial Demo', $tenant->name);
        $this->assertSame(0, $tenant->users()->count());
        $this->assertDatabaseCount('suppliers', 3);
        $this->assertDatabaseCount('documents', 6);
        $this->assertDatabaseHas('requirement_sets', ['name' => 'Homologação 2026', 'status' => 'published']);
        $this->assertEqualsCanonicalizing(['met', 'partial', 'missing', 'inconclusive'], DB::table('analysis_findings')->distinct()->pluck('status')->all());
        $this->assertDatabaseCount('supplier_decisions', 1);
        foreach (DB::table('documents')->get() as $document) {
            $blob = DB::table('document_blobs')->where('document_id', $document->id)->value('contents');
            $bytes = is_resource($blob) ? stream_get_contents($blob) : $blob;
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertSame($document->sha256, hash('sha256', $bytes));
            $this->assertSame($document->size_bytes, strlen($bytes));
            $this->assertSame(file_get_contents(base_path('../../demo-assets/'.$document->original_name)), $bytes);
        }
        foreach (DB::table('document_pages')->get() as $page) {
            $this->assertStringContainsString('DOCUMENTO FICTÍCIO - SOMENTE DEMONSTRAÇÃO', $page->text);
        }
        foreach (DB::table('finding_citations')->get() as $citation) {
            $page = DB::table('document_pages')->find($citation->document_page_id);
            $this->assertSame($citation->excerpt, mb_substr($page->text, $citation->start_offset, $citation->end_offset - $citation->start_offset));
        }
        $this->assertTrue(app(AuditHash::class)->verifyChain(AuditLog::orderBy('id')->get()));
    }

    public function test_clones_remap_document_uuids_and_create_fresh_audit_for_every_human_record(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoTemplateSeeder::class);
        $context = app(CurrentOrganization::class);
        $template = Organization::where('slug', 'demo-template')->sole();
        $context->set($template);
        $a = app(CreateDemoSession::class)->handle();
        $this->assertSame($template->id, $context->id(), 'Caller context must be restored');
        $b = app(CreateDemoSession::class)->handle();
        $context->clear();
        foreach ([$a, $b] as $session) {
            $tenant = $session->organization_id;
            $logs = AuditLog::where('organization_id', $tenant)->orderBy('id')->get();
            $humanCount = DB::table('finding_reviews')->where('organization_id', $tenant)->count() + DB::table('supplier_decisions')->where('organization_id', $tenant)->count();
            $this->assertGreaterThan(0, $humanCount);
            $this->assertCount($humanCount, $logs);
            $this->assertTrue(app(AuditHash::class)->verifyChain($logs));
            foreach ($logs as $log) {
                $this->assertSame($session->user_id, $log->actor_id);
                $this->assertSame($session->organization->public_id, $log->organization_public_id);
                $table = $log->action === 'finding.reviewed' ? 'finding_reviews' : 'supplier_decisions';
                $this->assertDatabaseHas($table, ['organization_id' => $tenant, 'public_id' => $log->target_public_id]);
            }
            foreach (DB::table('analysis_runs')->where('organization_id', $tenant)->get() as $run) {
                foreach (json_decode($run->document_ids, true) as $id) {
                    $this->assertDatabaseHas('documents', ['public_id' => $id, 'organization_id' => $tenant, 'supplier_id' => $run->supplier_id]);
                }
            }
            $this->assertSame(3, $session->suppliers_used);
            $this->assertSame(2, $session->analyses_used);
            $this->assertSame((int) DB::table('documents')->where('organization_id', $tenant)->sum('size_bytes'), $session->storage_used_bytes);
            $this->assertEqualsWithDelta(86400, now()->diffInSeconds($session->expires_at), 2);
        }
        foreach (['public_id', 'event_hash'] as $field) {
            $this->assertEmpty(AuditLog::where('organization_id', $a->organization_id)->pluck($field)->intersect(AuditLog::where('organization_id', $b->organization_id)->pluck($field))->all());
        }
    }

    private function snapshot(): array
    {
        return collect(['organizations', 'users', 'organization_user', 'suppliers', 'requirement_sets', 'requirements', 'documents', 'document_pages', 'document_chunks', 'analysis_runs', 'analysis_findings', 'finding_citations', 'finding_reviews', 'supplier_decisions', 'audit_logs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy($table === 'organization_user' ? 'user_id' : 'id')->get()->toJson()])->all();
    }

    public function test_supplier_detail_links_only_its_tenant_latest_analysis_for_authorized_viewers(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoTemplateSeeder::class);
        $tenantId = $this->postJson('/api/v1/demo-sessions')->assertCreated()->json('data.organization_id');
        $tenant = Organization::where('public_id', $tenantId)->sole();
        $supplier = DB::table('suppliers')->where('organization_id', $tenant->id)->where('name', 'NovaGuard Facilities')->sole();
        $run = DB::table('analysis_runs')->where('supplier_id', $supplier->id)->sole();
        $this->getJson('/api/v1/suppliers/'.$supplier->public_id)->assertOk()->assertJsonPath('data.latest_analysis.id', $run->public_id)->assertJsonPath('data.latest_analysis.status', 'completed');
        DB::table('role_permission')->where('permission_id', DB::table('permissions')->where('name', 'analysis.view')->value('id'))->delete();
        $this->getJson('/api/v1/suppliers/'.$supplier->public_id)->assertOk()->assertJsonMissingPath('data.latest_analysis');
    }

    public function test_audit_failure_rolls_back_seed_and_clone_without_leaking_context(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $logger = app(AuditLogger::class);
        $this->app->instance(AuditLogger::class, new class(app(AuditHash::class)) extends AuditLogger
        {
            public function record(AuditEvent $event): AuditLog
            {
                throw new \RuntimeException('Simulated audit failure');
            }
        });
        try {
            $this->seed(DemoTemplateSeeder::class);
            $this->fail('Seed must fail atomically');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated audit failure', $error->getMessage());
        }
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(app(CurrentOrganization::class)->has());
        $failure = app(AuditLogger::class);
        $this->app->instance(AuditLogger::class, $logger);
        $this->seed(DemoTemplateSeeder::class);
        $snapshot = $this->snapshot();
        $this->app->instance(AuditLogger::class, $failure);
        try {
            app(CreateDemoSession::class)->handle();
            $this->fail('Clone must fail atomically');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated audit failure', $error->getMessage());
        }
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertDatabaseCount('demo_sessions', 0);
        $this->assertFalse(app(CurrentOrganization::class)->has());
    }

    public function test_factories_create_a_consistent_tenant_graph_and_expiring_demo(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $finding = AnalysisFindingFactory::new()->create();
        $run = DB::table('analysis_runs')->find($finding->analysis_run_id);
        $requirement = DB::table('requirements')->find($finding->requirement_id);
        $this->assertSame($run->organization_id, $finding->organization_id);
        $this->assertSame($run->requirement_set_id, $requirement->requirement_set_id);
        $demo = DemoSessionFactory::new()->create();
        $this->assertEqualsWithDelta(86400, now()->diffInSeconds($demo->expires_at), 2);
        $this->assertDatabaseHas('organization_user', ['organization_id' => $demo->organization_id, 'user_id' => $demo->user_id]);
    }
}
