<?php

namespace Tests\Feature\Audit;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Review\ReviewTestCase;

class AuditPageIntegrityTest extends ReviewTestCase
{
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
