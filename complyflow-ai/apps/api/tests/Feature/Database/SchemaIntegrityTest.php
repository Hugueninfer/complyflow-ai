<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_receives_unique_public_uuid_while_preserving_internal_key(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->assertIsInt($firstUser->id);
        $this->assertTrue(Str::isUuid($firstUser->public_id));
        $this->assertNotSame($firstUser->public_id, $secondUser->public_id);
    }

    public function test_analysis_finding_rejects_confidence_outside_zero_to_one(): void
    {
        [$organizationId, $analysisRunId, $requirementId] = $this->findingDependencies();

        $this->expectException(QueryException::class);

        DB::table('analysis_findings')->insert([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'analysis_run_id' => $analysisRunId,
            'requirement_id' => $requirementId,
            'status' => 'met',
            'justification' => 'Invalid confidence must be rejected by the database.',
            'confidence' => 1.1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_document_hash_is_deduplicated_per_supplier_within_tenant(): void
    {
        $organizationId = $this->createOrganization();
        $firstSupplierId = $this->createSupplier($organizationId, 'Northwind Security');
        $secondSupplierId = $this->createSupplier($organizationId, 'Contoso Security');
        $sha256 = str_repeat('b', 64);

        $this->insertDocument($organizationId, $firstSupplierId, $sha256);
        $this->insertDocument($organizationId, $secondSupplierId, $sha256);

        $this->assertSame(2, DB::table('documents')->where('sha256', $sha256)->count());

        $this->expectException(QueryException::class);
        $this->insertDocument($organizationId, $firstSupplierId, $sha256);
    }

    /**
     * @return array{int, int, int}
     */
    private function findingDependencies(): array
    {
        $organizationId = $this->createOrganization();
        $supplierId = $this->createSupplier($organizationId, 'Northwind Security');
        $requirementSetId = DB::table('requirement_sets')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'name' => 'Baseline',
            'version' => 1,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $requirementId = DB::table('requirements')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'requirement_set_id' => $requirementSetId,
            'code' => 'SEC-001',
            'title' => 'Security policy',
            'category' => 'security',
            'weight' => 1,
            'position' => 1,
            'evaluation_text' => 'A current security policy must exist.',
            'is_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $analysisRunId = DB::table('analysis_runs')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'supplier_id' => $supplierId,
            'requirement_set_id' => $requirementSetId,
            'status' => 'completed',
            'attempts' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'document_set_hash' => str_repeat('a', 64),
            'progress' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$organizationId, $analysisRunId, $requirementId];
    }

    private function createOrganization(): int
    {
        return DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'name' => 'Northwind',
            'slug' => 'northwind-'.Str::lower(Str::random(6)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSupplier(int $organizationId, string $name): int
    {
        return DB::table('suppliers')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'name' => $name,
            'risk_level' => 'medium',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDocument(int $organizationId, int $supplierId, string $sha256): void
    {
        DB::table('documents')->insert([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'supplier_id' => $supplierId,
            'original_name' => 'policy.pdf',
            'storage_name' => (string) Str::uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => $sha256,
            'status' => 'uploaded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
