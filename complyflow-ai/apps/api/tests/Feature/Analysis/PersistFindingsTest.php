<?php

namespace Tests\Feature\Analysis;

use App\Data\Processor\ProcessorResult;
use App\Jobs\ProcessAnalysis;
use App\Models\AnalysisRun;
use App\Services\Analysis\PersistProcessorResult;
use App\Services\Analysis\ProcessorResultValidator;
use App\Services\Processor\ProcessorException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ProcessorResultFactory;

class PersistFindingsTest extends AnalysisTestCase
{
    public function test_distinct_analyses_reuse_identical_extraction_without_duplicating_chunks(): void
    {
        $first = $this->runAnalysis('first');
        app(PersistProcessorResult::class)->handle($first, ProcessorResultFactory::valid($first));
        $pageId = DB::table('document_pages')->value('public_id');
        $second = $this->runAnalysis('second');
        app(PersistProcessorResult::class)->handle($second, ProcessorResultFactory::valid($second));
        $this->assertSame('completed', $second->fresh()->status);
        $this->assertDatabaseCount('document_pages', 1);
        $this->assertDatabaseCount('document_chunks', 1);
        $this->assertDatabaseCount('analysis_findings', 2);
        $this->assertDatabaseCount('finding_citations', 2);
        $this->assertSame($pageId, DB::table('document_pages')->value('public_id'));
    }

    public function test_conflicting_reextraction_fails_without_changing_historical_evidence(): void
    {
        $first = $this->runAnalysis('first');
        app(PersistProcessorResult::class)->handle($first, ProcessorResultFactory::valid($first));
        $second = $this->runAnalysis('second');
        $raw = ProcessorResultFactory::payload($second);
        $raw['processed_documents'][0]['pages'][0]['text'] .= ' Changed extraction.';
        app(PersistProcessorResult::class)->handle($second, ProcessorResult::fromArray($raw));
        $this->assertSame('failed', $second->fresh()->status);
        $this->assertSame('invalid_processor_result', $second->fresh()->error_code);
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertDatabaseCount('finding_citations', 1);
        $this->assertDatabaseHas('document_pages', ['text' => 'Certidão válida. Texto privado fora da citação.']);
    }

    public function test_duplicate_citations_and_chunks_in_one_result_are_stored_once(): void
    {
        $run = $this->runAnalysis();
        $raw = ProcessorResultFactory::payload($run);
        $raw['findings'][0]['citations'][] = $raw['findings'][0]['citations'][0];
        $chunk = $raw['processed_documents'][0]['chunks'][0];
        $chunk['index'] = 1;
        $raw['processed_documents'][0]['chunks'][] = $chunk;
        app(PersistProcessorResult::class)->handle($run, ProcessorResult::fromArray($raw));
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('finding_citations', 1);
        $this->assertDatabaseCount('document_chunks', 1);
    }

    public function test_vector_outside_pgvector_float_range_and_nul_text_are_terminal_invalid_results(): void
    {
        foreach (['vector', 'nul'] as $case) {
            $run = $this->runAnalysis($case);
            $raw = ProcessorResultFactory::payload($run);
            if ($case === 'vector') {
                $raw['processed_documents'][0]['chunks'][0]['embedding'][0] = 1e100;
            } else {
                $raw['findings'][0]['justification'] = "Private\0text";
            }
            app(PersistProcessorResult::class)->handle($run, ProcessorResult::fromArray($raw));
            $this->assertSame('failed', $run->fresh()->status);
            $this->assertSame('invalid_processor_result', $run->fresh()->error_code);
            $this->assertDatabaseCount('document_pages', 0);
            $this->assertDatabaseCount('finding_citations', 0);
        }
    }

    protected function runAnalysis(string $key = 'persist'): AnalysisRun
    {
        $id = $this->start($key)->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $run->update(['status' => 'processing', 'attempts' => 1, 'started_at' => now(), 'owner_message_uuid' => (string) Str::uuid(), 'owner_reservation_id' => '71', 'owner_reservation_attempt' => 1]);

        return $run;
    }

    public function test_completed_result_persists_pages_vectors_findings_and_citations_once(): void
    {
        $run = $this->runAnalysis();
        $result = ProcessorResultFactory::valid($run);
        app(PersistProcessorResult::class)->handle($run, $result);
        app(PersistProcessorResult::class)->handle($run, $result);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(100, $run->fresh()->progress);
        $this->assertNotNull($run->fresh()->completed_at);
        foreach (['document_pages', 'document_chunks', 'analysis_findings', 'finding_citations'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertSame(384, DB::selectOne('select vector_dims(embedding) as dimensions from document_chunks')->dimensions);
        $this->assertDatabaseHas('finding_citations', ['organization_id' => $run->organization_id, 'excerpt' => 'Certidão válida.', 'start_offset' => 0, 'end_offset' => 16]);
        $this->assertDatabaseHas('documents', ['id' => $this->document->id, 'status' => 'ready']);
    }

    public function test_validator_rechecks_database_context_before_accepting_result(): void
    {
        $run = $this->runAnalysis();
        $result = ProcessorResultFactory::valid($run);
        $this->set->update(['status' => 'draft']);
        try {
            app(ProcessorResultValidator::class)->validate($run, $result);
            $this->fail('An unpublished checklist cannot supply accepted findings.');
        } catch (ProcessorException $error) {
            $this->assertSame('invalid_processor_result', $error->publicCode);
        }
    }

    public function test_invalid_requirement_set_is_terminal_without_partial_artifacts(): void
    {
        $run = $this->runAnalysis();
        $raw = ProcessorResultFactory::payload($run);
        $raw['findings'] = [];
        app(PersistProcessorResult::class)->handle($run, ProcessorResult::fromArray($raw));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('invalid_processor_result', $run->fresh()->error_code);
        $this->assertSame('Não foi possível processar a análise.', $run->fresh()->error_message);
        $this->assertNotNull($run->fresh()->completed_at);
        foreach (['document_pages', 'document_chunks', 'analysis_findings', 'finding_citations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_stale_attempt_and_different_reservation_cannot_persist_or_fail_current_owner(): void
    {
        $run = $this->runAnalysis();
        $result = ProcessorResultFactory::valid($run);
        $run->fresh()->update(['owner_reservation_id' => '72']);
        app(PersistProcessorResult::class)->handle($run, $result);
        $this->assertSame('processing', $run->fresh()->status);
        $this->assertDatabaseCount('analysis_findings', 0);
        $run->refresh();
        $run->fresh()->update(['attempts' => 2]);
        $raw = ProcessorResultFactory::payload($run);
        $raw['findings'] = [];
        app(PersistProcessorResult::class)->handle($run, ProcessorResult::fromArray($raw));
        $this->assertSame('processing', $run->fresh()->status);
        $this->assertNull($run->fresh()->error_code);
    }

    public function test_write_failure_rolls_back_all_artifacts_and_retry_can_complete(): void
    {
        $run = $this->runAnalysis();
        DB::statement("ALTER TABLE finding_citations ADD CONSTRAINT task10_injected_failure CHECK (excerpt <> 'Certidão válida.')");
        try {
            app(PersistProcessorResult::class)->handle($run, ProcessorResultFactory::valid($run));
            $this->fail('The injected database constraint must abort the transaction.');
        } catch (ProcessorException $error) {
            $this->assertSame('analysis_failed', $error->publicCode);
            $this->assertTrue($error->retryable);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('Certidão', (string) $error);
        } finally {
            DB::statement('ALTER TABLE finding_citations DROP CONSTRAINT task10_injected_failure');
        }
        foreach (['document_pages', 'document_chunks', 'analysis_findings', 'finding_citations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('processing', $run->fresh()->status);
        app(PersistProcessorResult::class)->handle($run, ProcessorResultFactory::valid($run));
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_bound_job_persists_result_and_duplicate_delivery_is_a_noop(): void
    {
        $run = $this->runAnalysis();
        config(['services.processor.url' => 'http://processor:8001', 'services.processor.secret' => 'test-only-secret']);
        Http::fake(['processor:8001/*' => Http::response(ProcessorResultFactory::payload($run))]);
        app()->call([new ProcessAnalysis($run->id), 'handle']);
        app()->call([new ProcessAnalysis($run->id), 'handle']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('finding_citations', 1);
        Http::assertSentCount(1);
    }
}
