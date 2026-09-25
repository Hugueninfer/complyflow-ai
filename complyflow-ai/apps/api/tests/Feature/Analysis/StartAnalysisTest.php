<?php

namespace Tests\Feature\Analysis;

use App\Jobs\ProcessAnalysis;
use App\Models\AnalysisRun;
use App\Models\RequirementSet;
use App\Services\Processor\ProcessorClient;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class StartAnalysisTest extends AnalysisTestCase
{
    public function test_legacy_cross_lineage_name_collision_rejects_analysis_before_quota_or_queue(): void
    {
        $demo = $this->demo();
        app(CurrentOrganization::class)->set($this->organization);
        $other = RequirementSet::create(['name' => 'Other', 'version' => 1, 'status' => 'published']);
        RequirementSet::create(['parent_id' => $other->id, 'name' => $this->set->name, 'version' => 2, 'status' => 'published']);
        $this->start()->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(0, $demo->fresh()->analyses_used);
        $this->assertDatabaseCount('analysis_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_legacy_published_zero_weight_is_rejected_before_queue_and_quota(): void
    {
        $demo = $this->demo();
        $this->set->requirements()->update(['weight' => 0]);
        $this->start()->assertUnprocessable()->assertJsonValidationErrors('requirements.0.weight');
        $this->assertSame(0, $demo->fresh()->analyses_used);
        $this->assertDatabaseCount('analysis_runs', 0);
        Queue::assertNothingPushed();
        $owner = $this->user($this->organization, 'owner');
        $requirement = ['code' => 'MIN', 'title' => 'Minimum weight', 'category' => 'Compliance', 'weight' => 1, 'evaluation_text' => 'Find evidence'];
        $setId = $this->actingAs($owner)->postJson('/api/v1/requirement-sets', ['name' => 'Positive checklist', 'requirements' => [$requirement]])
            ->assertCreated()->json('data.id');
        $requirement['weight'] = 0.001;
        $this->putJson('/api/v1/requirement-sets/'.$setId, ['requirements' => [$requirement]])->assertOk();
        $this->postJson('/api/v1/requirement-sets/'.$setId.'/publish')->assertOk();
        $this->set = RequirementSet::where('public_id', $setId)->firstOrFail();
        $runId = $this->start()->assertStatus(202)->json('data.id');
        Queue::assertPushed(ProcessAnalysis::class, 1);
        config(['services.processor.url' => 'http://processor:8001', 'services.processor.secret' => 'test-only-secret']);
        Http::fake(['processor:8001/*' => Http::response($this->processorResult($runId))]);
        $result = app(ProcessorClient::class)->analyze(AnalysisRun::where('public_id', $runId)->firstOrFail());
        $this->assertSame($runId, $result->analysisId);
        Http::assertSent(fn ($request) => $request['requirements'][0]['weight'] === 0.001);
    }

    public function test_empty_published_checklist_is_rejected_without_consuming_quota(): void
    {
        $demo = $this->demo();
        $this->set->requirements()->delete();
        $this->start()->assertUnprocessable()->assertJsonPath('message', 'Checklist must contain between 1 and 100 requirements.');
        $this->assertSame(0, $demo->fresh()->analyses_used);
        $this->assertDatabaseCount('analysis_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_checklist_limit_is_inclusive_and_excess_does_not_consume_quota(): void
    {
        $demo = $this->demo();
        $requirement = $this->set->requirements()->firstOrFail();
        for ($index = 2; $index <= 100; $index++) {
            $copy = $requirement->replicate(['public_id']);
            $copy->forceFill(['code' => 'R'.$index, 'position' => $index])->save();
        }
        $this->start('exact-limit')->assertStatus(202);
        $copy = $requirement->replicate(['public_id']);
        $copy->forceFill(['code' => 'R101', 'position' => 101])->save();
        $this->start('above-limit')->assertUnprocessable()->assertJsonPath('message', 'Checklist must contain between 1 and 100 requirements.');
        $this->assertSame(1, $demo->fresh()->analyses_used);
        $this->assertDatabaseCount('analysis_runs', 1);
        Queue::assertPushed(ProcessAnalysis::class, 1);
    }

    public function test_total_pdf_byte_limit_is_inclusive_and_excess_does_not_consume_quota(): void
    {
        $demo = $this->demo();
        $ids = [$this->document->public_id];
        $this->document->update(['size_bytes' => 5 * 1024 * 1024]);
        for ($index = 1; $index <= 2; $index++) {
            $copy = $this->document->replicate(['public_id']);
            $copy->forceFill(['storage_name' => Str::uuid().'.pdf', 'sha256' => hash('sha256', 'budget-'.$index)])->save();
            $ids[] = $copy->public_id;
        }
        $payload = array_replace($this->payload(), ['document_ids' => $ids]);
        $this->postJson($this->analysisUrl(), $payload, ['Idempotency-Key' => 'exact-bytes'])->assertStatus(202);
        $copy = $this->document->replicate(['public_id']);
        $copy->forceFill(['storage_name' => Str::uuid().'.pdf', 'sha256' => hash('sha256', 'excess-byte'), 'size_bytes' => 1])->save();
        $payload['document_ids'][] = $copy->public_id;
        $this->postJson($this->analysisUrl(), $payload, ['Idempotency-Key' => 'over-bytes'])->assertUnprocessable()
            ->assertJsonPath('message', 'Selected PDFs must not exceed 15 MiB in total.');
        $this->assertSame(1, $demo->fresh()->analyses_used);
        $this->assertDatabaseCount('analysis_runs', 1);
        Queue::assertPushed(ProcessAnalysis::class, 1);
    }

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
