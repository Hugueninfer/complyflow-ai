<?php

namespace Tests\Feature\Analysis;

use App\Jobs\ProcessAnalysis;
use App\Models\AnalysisRun;
use Illuminate\Support\Facades\Queue;

class RetryAnalysisTest extends AnalysisTestCase
{
    public function test_retry_revalidates_selection_and_demo_quota(): void
    {
        $demo = $this->demo();
        $demo->update(['analysis_quota' => 1]);
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $run->update(['status' => 'failed']);
        $url = '/api/v1/analyses/'.$id.'/retry';
        $this->postJson($url, [], ['Idempotency-Key' => 'quota'])->assertStatus(429);
        $this->assertSame(1, $demo->fresh()->analyses_used);
        $demo->update(['analysis_quota' => 10]);
        $run->update(['document_ids' => []]);
        $this->postJson($url, [], ['Idempotency-Key' => 'empty'])->assertUnprocessable();
        $this->assertDatabaseCount('analysis_runs', 1);
    }

    public function test_retry_creates_one_new_run_and_preserves_failed_original(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $run->update(['status' => 'failed']);
        $url = '/api/v1/analyses/'.$id.'/retry';
        $this->postJson($url)->assertUnprocessable();
        $next = $this->postJson($url, [], ['Idempotency-Key' => 'retry-1'])->assertStatus(202)->json('data.id');
        $this->assertNotSame($id, $next);
        $this->postJson($url, [], ['Idempotency-Key' => 'retry-1'])->assertOk()->assertJsonPath('data.id', $next);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame($run->document_ids, AnalysisRun::where('public_id', $next)->firstOrFail()->document_ids);
        Queue::assertPushed(ProcessAnalysis::class, 2);
    }

    public function test_retry_requires_failed_state_permission_and_tenant(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $url = '/api/v1/analyses/'.$id.'/retry';
        $this->postJson($url, [], ['Idempotency-Key' => 'retry'])->assertConflict();
        AnalysisRun::where('public_id', $id)->update(['status' => 'failed']);
        $this->actingAs($this->user($this->organization, 'reviewer'))->postJson($url, [], ['Idempotency-Key' => 'retry'])->assertForbidden();
        $this->actingAs($this->user($this->organization(), 'owner'))->postJson($url, [], ['Idempotency-Key' => 'retry'])->assertNotFound();
        $this->assertDatabaseCount('analysis_runs', 1);
    }
}
