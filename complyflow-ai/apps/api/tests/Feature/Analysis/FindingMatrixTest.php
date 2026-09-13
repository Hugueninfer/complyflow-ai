<?php

namespace Tests\Feature\Analysis;

use App\Data\Processor\ProcessorResult;
use App\Models\AnalysisRun;
use App\Models\Role;
use App\Services\Analysis\PersistProcessorResult;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessorResultFactory;

class FindingMatrixTest extends AnalysisTestCase
{
    public function test_all_statuses_are_sorted_by_requirement_position_independently_of_processor_order(): void
    {
        app(CurrentOrganization::class)->set($this->organization);
        foreach ([['B', 0], ['Z', 0], ['M', 2]] as [$code, $position]) {
            $this->set->requirements()->create(['organization_id' => $this->organization->id, 'code' => $code, 'title' => $code, 'category' => 'Compliance', 'weight' => 1, 'position' => $position, 'evaluation_text' => 'Find evidence.']);
        }
        app(CurrentOrganization::class)->clear();
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $run->update(['status' => 'processing', 'attempts' => 1]);
        $raw = ProcessorResultFactory::payload($run);
        $statuses = ['B' => 'missing', 'Z' => 'partial', 'CERT' => 'met', 'M' => 'inconclusive'];
        $requirements = $this->set->requirements()->get()->keyBy('public_id');
        foreach ($raw['findings'] as &$finding) {
            $finding['status'] = $statuses[$requirements[$finding['requirement_id']]->code];
            if ($finding['status'] === 'missing') {
                $finding['citations'] = [];
                $finding['search_summary'] = 'Busca realizada em todas as páginas.';
            }
        }
        unset($finding);
        $raw['findings'] = array_reverse($raw['findings']);
        app(PersistProcessorResult::class)->handle($run, ProcessorResult::fromArray($raw));
        $response = $this->getJson('/api/v1/analyses/'.$id.'/findings')->assertOk();
        $this->assertSame(['B', 'Z', 'CERT', 'M'], array_column(array_column($response->json('data'), 'requirement'), 'code'));
        $this->assertSame(['missing', 'partial', 'met', 'inconclusive'], array_column($response->json('data'), 'status'));
        $response->assertJsonPath('data.0.citations', [])->assertJsonPath('data.0.search_summary', 'Busca realizada em todas as páginas.');
        $this->assertDatabaseCount('supplier_decisions', 0);
        $this->assertDatabaseCount('finding_reviews', 0);
    }

    public function test_matrix_requires_analysis_view_permission_and_authentication(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $url = '/api/v1/analyses/'.$id.'/findings';
        $role = Role::where('name', 'analyst')->firstOrFail();
        DB::table('role_permission')->where('role_id', $role->id)->delete();
        $this->getJson($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_pending_matrix_is_empty_and_scoped_to_authorized_tenant(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $url = '/api/v1/analyses/'.$id.'/findings';
        $this->getJson($url)->assertOk()->assertExactJson(['data' => []]);
        $this->actingAs($this->user($this->organization, 'reviewer'))->getJson($url)->assertOk();
        $this->actingAs($this->user($this->organization(), 'owner'))->getJson($url)->assertNotFound();
        $this->getJson('/api/v1/analyses/not-a-uuid/findings')->assertNotFound();
    }

    public function test_matrix_contains_public_requirement_and_evidence_without_page_text_or_storage_data(): void
    {
        $id = $this->start()->assertStatus(202)->json('data.id');
        $run = AnalysisRun::where('public_id', $id)->firstOrFail();
        $run->update(['status' => 'processing', 'attempts' => 1]);
        app(PersistProcessorResult::class)->handle($run, ProcessorResultFactory::valid($run));
        $response = $this->getJson('/api/v1/analyses/'.$id.'/findings')->assertOk();
        $response->assertJsonPath('data.0.requirement.id', $this->set->requirements()->first()->public_id)
            ->assertJsonPath('data.0.requirement.code', 'CERT')->assertJsonPath('data.0.status', 'met')
            ->assertJsonPath('data.0.confidence', 0.8)->assertJsonPath('data.0.requires_human_review', true)
            ->assertJsonPath('data.0.citations.0.document_id', $this->document->public_id)
            ->assertJsonPath('data.0.citations.0.page_number', 1)->assertJsonPath('data.0.citations.0.quote', 'Certidão válida.');
        $body = $response->getContent();
        foreach (['Texto privado', 'organization_id', 'storage_name', 'embedding', 'document_page_id', 'analysis_run_id', 'content_base64'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
    }
}
