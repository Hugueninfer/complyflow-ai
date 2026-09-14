<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Services\Audit\AuditEvent;
use App\Services\Audit\AuditHash;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Review\ReviewTestCase;

class AuditChainTest extends ReviewTestCase
{
    public function test_logger_rejects_unknown_or_sensitive_metadata_before_persisting(): void
    {
        app(CurrentOrganization::class)->set($this->organization);
        foreach ([['status' => $this->reviewer->email], ['status' => 'met', 'email' => $this->reviewer->email]] as $metadata) {
            $metadata += ['finding_id' => $this->finding->public_id, 'analysis_id' => $this->run->public_id];
            try {
                app(AuditLogger::class)->record(new AuditEvent($this->reviewer, 'finding.reviewed', 'finding_review', $this->finding->public_id, $metadata));
                $this->fail('Unapproved metadata must never enter audit storage.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('audit_logs', 0);
            }
        }
    }

    public function test_logger_refuses_an_actor_from_another_tenant(): void
    {
        $foreign = $this->user($this->organization(), 'reviewer');
        app(CurrentOrganization::class)->set($this->organization);
        $this->expectException(AuthorizationException::class);
        app(AuditLogger::class)->record(new AuditEvent($foreign, 'finding.reviewed', 'finding_review', $this->finding->public_id, ['finding_id' => $this->finding->public_id, 'analysis_id' => $this->run->public_id, 'status' => 'met']));
    }

    public function test_expired_demo_purge_removes_all_history(): void
    {
        $this->review()->assertCreated();
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertCreated();
        $demo = $this->demo();
        $demo->update(['expires_at' => now()->subMinute()]);
        $this->artisan('demo:purge-expired')->assertSuccessful();
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('finding_reviews', 0);
        $this->assertDatabaseCount('supplier_decisions', 0);
        $this->assertDatabaseMissing('organizations', ['id' => $this->organization->id]);
    }

    public function test_chain_verifies_and_detects_tampering_with_each_signed_field(): void
    {
        $review = $this->review()->assertCreated()->json('data.id');
        $this->postJson($this->reviewUrl(), $this->reviewPayload() + ['expected_review_id' => $review], ['Idempotency-Key' => 'second'])->assertCreated();
        $logs = AuditLog::orderBy('id')->get();
        $this->assertNull($logs[0]->previous_hash);
        $this->assertSame($logs[0]->event_hash, $logs[1]->previous_hash);
        $hash = app(AuditHash::class);
        $this->assertTrue($hash->verifyChain($logs));
        foreach (['public_id', 'organization_public_id', 'actor_public_id', 'action', 'target_type', 'target_public_id', 'occurred_at', 'metadata', 'previous_hash'] as $field) {
            $copy = clone $logs[1];
            $copy->setAttribute($field, match ($field) {
                'occurred_at' => now()->addDay(), 'metadata' => ['status' => 'met'], default => 'tampered'
            });
            $this->assertFalse($hash->verifyChain(collect([$logs[0], $copy])), $field);
        }
        $this->assertFalse($hash->verifyChain($logs->reverse()));
        $this->assertFalse($hash->verifyChain(collect([$logs[1]])));
    }

    public function test_hash_sorts_nested_object_keys_preserves_array_order_and_normalizes_utc(): void
    {
        $this->review()->assertCreated();
        $first = AuditLog::firstOrFail();
        $second = clone $first;
        $first->metadata = ['z' => ['b' => 2, 'a' => 1], 'a' => [1, 2]];
        $second->metadata = ['a' => [1, 2], 'z' => ['a' => 1, 'b' => 2]];
        $first->occurred_at = '2026-09-13T12:00:00-03:00';
        $second->occurred_at = '2026-09-13T15:00:00Z';
        $hash = app(AuditHash::class);
        $this->assertSame($hash->make($first), $hash->make($second));
        $second->metadata = ['a' => [2, 1], 'z' => ['a' => 1, 'b' => 2]];
        $this->assertNotSame($hash->make($first), $hash->make($second));
    }

    public function test_audit_is_paginated_ordered_scoped_and_permission_checked(): void
    {
        $review = $this->review()->assertCreated()->json('data.id');
        $this->postJson($this->reviewUrl(), $this->reviewPayload() + ['expected_review_id' => $review], ['Idempotency-Key' => 'second'])->assertCreated();
        $expected = DB::table('audit_logs')->orderByDesc('id')->value('public_id');
        $this->getJson('/api/v1/audit-logs?per_page=1')->assertOk()->assertJsonPath('data.0.id', $expected)->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonMissingPath('data.0.actor_id')->assertJsonMissingPath('data.0.organization_id');
        $this->getJson('/api/v1/audit-logs?per_page=101')->assertUnprocessable();
        $this->actingAs($this->analyst)->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->actingAs($this->user($this->organization(), 'reviewer'))->getJson('/api/v1/audit-logs')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_model_rejects_audit_deletion(): void
    {
        $this->review()->assertCreated();
        $this->expectException(\LogicException::class);
        AuditLog::firstOrFail()->delete();
    }

    public function test_database_rejects_direct_audit_update(): void
    {
        $this->review()->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->update(['action' => 'tampered']);
    }

    public function test_database_rejects_direct_audit_delete_even_with_purge_flag_for_active_tenant(): void
    {
        $this->review()->assertCreated();
        $this->demo();
        DB::select('select set_config(?, ?, true)', ['complyflow.purge_demo', (string) $this->organization->id]);
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->delete();
    }

    public function test_stored_tampering_is_detected_even_if_a_database_admin_bypasses_the_trigger(): void
    {
        $this->review()->assertCreated();
        DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_immutable');
        try {
            DB::table('audit_logs')->update(['action' => 'tampered']);
        } finally {
            DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_immutable');
        }
        $this->assertFalse(app(AuditHash::class)->verifyChain(AuditLog::orderBy('id')->get()));
    }

    public function test_database_rejects_direct_review_delete(): void
    {
        $this->review()->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('finding_reviews')->delete();
    }

    public function test_database_rejects_direct_decision_update(): void
    {
        $this->review()->assertCreated();
        $this->postJson($this->decisionUrl(), $this->decisionPayload(), ['Idempotency-Key' => 'decision'])->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('supplier_decisions')->update(['decision' => 'approved']);
    }
}
