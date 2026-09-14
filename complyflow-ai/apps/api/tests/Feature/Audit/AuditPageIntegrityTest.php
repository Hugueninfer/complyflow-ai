<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Services\Audit\AuditHash;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Review\ReviewTestCase;

class AuditPageIntegrityTest extends ReviewTestCase
{
    public function test_existing_legacy_boundary_is_not_mistaken_for_a_legitimate_root(): void
    {
        $this->review()->assertCreated();
        $this->getJson('/api/v1/audit-logs?per_page=1')->assertJsonPath('meta.integrity.status', 'verified');
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'final'])->assertCreated();
        $older = AuditLog::orderBy('id')->firstOrFail();
        $modern = AuditLog::orderByDesc('id')->firstOrFail();
        $modern->previous_hash = null;
        $modernHash = app(AuditHash::class)->make($modern);
        // Simulate an imported legacy predecessor lacking a hash. PostgreSQL
        // DDL and data changes are isolated by RefreshDatabase's transaction.
        DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_immutable');
        DB::statement('ALTER TABLE audit_logs ALTER COLUMN event_hash DROP NOT NULL');
        try {
            DB::table('audit_logs')->where('id', $older->id)->update(['organization_public_id' => null, 'event_hash' => null]);
            DB::table('audit_logs')->where('id', $modern->id)->update(['previous_hash' => null, 'event_hash' => $modernHash]);
            $this->getJson('/api/v1/audit-logs?per_page=1')->assertJsonPath('meta.integrity.status', 'unverifiable');
            DB::table('audit_logs')->where('id', $modern->id)->update(['action' => 'tampered']);
            $this->getJson('/api/v1/audit-logs?per_page=1')->assertJsonPath('meta.integrity.status', 'broken');
        } finally {
            DB::table('audit_logs')->where('id', $older->id)->update(['event_hash' => $older->event_hash]);
            DB::statement('ALTER TABLE audit_logs ALTER COLUMN event_hash SET NOT NULL');
            DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_immutable');
        }
    }

    public function test_page_verifies_hashes_and_boundary_without_claiming_full_chain(): void
    {
        $this->getJson('/api/v1/audit-logs')->assertJsonPath('meta.integrity.status', 'empty');
        $this->review()->assertCreated();
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'final'])->assertCreated();
        $this->getJson('/api/v1/audit-logs?per_page=1')->assertJsonPath('meta.integrity.status', 'verified')->assertJsonPath('meta.integrity.scope', 'page');
        $this->getJson('/api/v1/audit-logs?per_page=1&page=2')->assertJsonPath('meta.integrity.status', 'verified');
        DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_immutable');
        try {
            DB::table('audit_logs')->where('action', 'supplier.decided')->update(['previous_hash' => str_repeat('0', 64)]);
        } finally {
            DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_immutable');
        }
        $this->getJson('/api/v1/audit-logs?per_page=1')->assertJsonPath('meta.integrity.status', 'broken');
    }
}
