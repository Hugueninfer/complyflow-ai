<?php

namespace Tests\Feature\Analysis;

use App\Jobs\ProcessAnalysis;
use App\Models\AnalysisRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class StartAnalysisTest extends AnalysisTestCase
{
    public function test_rejects_selection_exceeding_processor_document_limit(): void
    {
        $ids = [$this->document->public_id];
        for ($index = 0; $index < 10; $index++) {
            $copy = $this->document->replicate(['public_id']);
            $copy->forceFill(['storage_name' => Str::uuid().'.pdf', 'sha256' => hash('sha256', (string) $index)])->save();
            $ids[] = $copy->public_id;
        }
        $this->postJson($this->analysisUrl(), array_replace($this->payload(), ['document_ids' => $ids]), ['Idempotency-Key' => 'too-many'])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_demo_quota_counts_new_runs_only_and_records_usage(): void
    {
        $demo = $this->demo();
        $demo->update(['analysis_quota' => 1]);
        $this->start()->assertStatus(202);
        $this->start()->assertOk();
        $this->start('over-quota')->assertStatus(429);
        $this->assertSame(1, $demo->fresh()->analyses_used);
        $this->assertDatabaseCount('analysis_runs', 1);
        Queue::assertPushed(ProcessAnalysis::class, 1);
    }

    public function test_document_order_does_not_change_fingerprint(): void
    {
        $second = $this->postJson($this->url(), ['file' => $this->pdf(marker: 'b')])->assertCreated()->json('data.id');
        $payload = $this->payload();
        $payload['document_ids'][] = $second;
        $first = $this->postJson($this->analysisUrl(), $payload, ['Idempotency-Key' => 'ordered'])->assertStatus(202)->json('data.id');
        $payload['document_ids'] = array_reverse($payload['document_ids']);
        $this->postJson($this->analysisUrl(), $payload, ['Idempotency-Key' => 'ordered'])->assertOk()->assertJsonPath('data.id', $first);
        Queue::assertPushed(ProcessAnalysis::class, 1);
    }

    public function test_same_key_returns_same_analysis_and_one_job_after_commit(): void
    {
        $first = $this->start()->assertStatus(202)->assertJsonPath('data.status', 'pending');
        $second = $this->start()->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        Queue::assertPushed(ProcessAnalysis::class, 1);
        Queue::assertPushed(ProcessAnalysis::class, fn ($job) => $job->afterCommit === true);
        $this->assertDatabaseCount('analysis_runs', 1);
        $this->getJson('/api/v1/analyses/'.$first->json('data.id'))->assertOk()
            ->assertJsonMissingPath('data.organization_id')->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonPath('data.attempts', 0)->assertJsonPath('data.completed_at', null);
    }

    public function test_creation_and_reads_enforce_tenant_and_permissions(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $this->actingAs($this->user($this->organization, 'reviewer'));
        $this->start('reviewer')->assertForbidden();
        $this->getJson('/api/v1/analyses/'.$id)->assertOk();
        $this->actingAs($this->user($this->organization(), 'owner'));
        $this->start()->assertNotFound();
        $this->getJson('/api/v1/analyses/'.$id)->assertNotFound();
        $this->actingAs($this->analyst);
        DB::table('role_permission')->delete();
        $this->getJson('/api/v1/analyses/'.$id)->assertForbidden();
    }

    public function test_validates_key_and_related_ids_without_creating_jobs(): void
    {
        foreach (['', str_repeat('a', 256), 'key with spaces', "bad\nkey"] as $key) {
            $this->start($key)->assertUnprocessable();
        }
        $this->postJson($this->analysisUrl(), $this->payload())->assertUnprocessable();
        $foreign = $this->supplier($this->organization());
        $this->postJson('/api/v1/suppliers/'.$foreign->public_id.'/analyses', [], ['Idempotency-Key' => 'valid'])->assertNotFound();
        foreach ([['document_ids' => [$this->supplier->public_id]], ['requirement_set_id' => $this->supplier->public_id], ['document_ids' => []], ['document_ids' => [$this->document->public_id, $this->document->public_id]]] as $override) {
            $this->postJson($this->analysisUrl(), array_replace($this->payload(), $override), ['Idempotency-Key' => 'valid'])->assertUnprocessable();
        }
        $this->set->update(['status' => 'draft']);
        $this->start()->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_same_key_with_changed_input_conflicts_and_new_key_creates_new_run(): void
    {
        $this->start()->assertStatus(202);
        $this->set->update(['version' => 2]);
        $this->start()->assertStatus(409);
        $this->start('new-version')->assertStatus(202);
        $this->assertCount(2, AnalysisRun::all()->pluck('document_set_hash')->unique());
        Queue::assertPushed(ProcessAnalysis::class, 2);
    }
}
