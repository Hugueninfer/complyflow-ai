<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\RequirementSet;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Analysis\AnalysisFingerprint;
use App\Services\Audit\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DemoTemplateSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            // A transaction-scoped lock also serializes the first seed, before a row exists.
            DB::select('SELECT pg_advisory_xact_lock(170026, 1)');
            $existing = Organization::withTrashed()->where('slug', 'demo-template')->first();
            if ($existing) {
                if ($existing->name !== 'Atlas Industrial Demo' || $existing->trashed() || $existing->users()->exists()) {
                    throw new RuntimeException('Reserved demo template is not the expected inaccessible template.');
                }

                return;
            }
            $assets = json_decode(file_get_contents(base_path('../../demo-assets/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
            foreach ($assets as &$asset) {
                $asset['bytes'] = file_get_contents(base_path('../../demo-assets/'.$asset['filename']));
                if (hash('sha256', $asset['bytes']) !== $asset['sha256'] || strlen($asset['bytes']) !== $asset['size_bytes']) {
                    throw new RuntimeException('Demo PDF does not match its extraction manifest.');
                }
            }
            unset($asset);
            $tenant = Organization::create(['name' => 'Atlas Industrial Demo', 'slug' => 'demo-template']);
            $context = app(CurrentOrganization::class);
            $previous = $context->has() ? $context->id() : null;
            $context->set($tenant);
            try {
                foreach (['owner', 'analyst', 'reviewer'] as $role) {
                    $actors[$role] = User::create(['name' => 'Ator fictício / '.$role, 'email' => 'template-'.$role.'@example.invalid', 'password' => Str::random(64)]);
                }
                $actor = $actors['reviewer'];
                // Authorization exists only inside this transaction and is removed before commit.
                // No template user can log into the reserved organization.
                $tenant->users()->attach($actor, ['role_id' => Role::where('name', 'reviewer')->sole()->id]);
                $set = RequirementSet::create(['name' => 'Homologação 2026', 'version' => 1, 'status' => 'published', 'published_at' => now()]);
                $titles = ['Regularidade documental', 'Política de privacidade', 'Plano de continuidade', 'Capacidade financeira'];
                $criteria = ['Verificar declaração cadastral para 2026.', 'Verificar finalidade, acesso e prazo objetivo de retenção de dados pessoais.', 'Localizar plano de continuidade operacional.', 'Verificar informações auditadas suficientes para avaliar liquidez.'];
                foreach ($titles as $index => $title) {
                    $requirements[] = $this->insert('requirements', $tenant->id, ['requirement_set_id' => $set->id, 'code' => 'DEMO-0'.($index + 1), 'title' => $title, 'category' => ['Documental', 'Privacidade', 'Operacional', 'Financeira'][$index], 'evaluation_text' => $criteria[$index], 'weight' => 1, 'position' => $index + 1, 'is_required' => true]);
                }
                foreach (['NovaGuard Facilities', 'Boreal Suprimentos Demo', 'Vértice Logística Demo'] as $index => $name) {
                    $supplier = Supplier::create(['name' => $name, 'risk_level' => ['medium', 'high', 'low'][$index]]);
                    if ($index === 2) {
                        continue;
                    }
                    $documents = [];
                    foreach ($assets as $asset) {
                        $document = $this->insert('documents', $tenant->id, ['supplier_id' => $supplier->id, 'original_name' => $asset['filename'], 'storage_name' => Str::uuid().'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => $asset['size_bytes'], 'sha256' => $asset['sha256'], 'status' => 'ready']);
                        // Explicit bytea encoding avoids text-driver corruption of compressed PDF bytes.
                        $this->insert('document_blobs', $tenant->id, ['document_id' => $document->id, 'contents' => DB::raw("decode('".bin2hex($asset['bytes'])."', 'hex')")]);
                        foreach ($asset['pages'] as $pageIndex => $text) {
                            $page = $this->insert('document_pages', $tenant->id, ['document_id' => $document->id, 'page_number' => $pageIndex + 1, 'text' => $text]);
                            $this->insert('document_chunks', $tenant->id, ['document_id' => $document->id, 'document_page_id' => $page->id, 'content' => $text, 'start_offset' => 0, 'end_offset' => mb_strlen($text)]);
                        }
                        $documents[] = ['document' => $document, 'page' => $page, 'excerpt' => $asset['excerpt']];
                    }
                    $run = $this->insert('analysis_runs', $tenant->id, ['supplier_id' => $supplier->id, 'requirement_set_id' => $set->id, 'status' => 'completed', 'attempts' => 1, 'idempotency_key' => 'demo-curated-'.$index, 'document_ids' => json_encode(array_column(array_column($documents, 'document'), 'public_id')), 'document_set_hash' => AnalysisFingerprint::make($supplier, $set, array_column($assets, 'sha256')), 'progress' => 100, 'started_at' => now(), 'completed_at' => now()]);
                    foreach (['met', 'partial', 'missing', 'inconclusive'] as $position => $status) {
                        $finding = $this->insert('analysis_findings', $tenant->id, ['analysis_run_id' => $run->id, 'requirement_id' => $requirements[$position]->id, 'status' => $status, 'justification' => ['Exemplo fictício: declaração cobre o período solicitado.', 'Exemplo fictício: finalidade e acesso descritos, mas prazo de retenção não definido.', 'Exemplo fictício: nenhum plano de continuidade localizado.', 'Exemplo fictício: números sem auditoria e sem projeções de liquidez.'][$position], 'confidence' => [.94, .81, .88, .45][$position], 'search_summary' => $status === 'missing' ? 'Busca por plano de continuidade em todas as páginas dos três PDFs fictícios; nenhuma evidência localizada.' : null]);
                        if ($status !== 'missing') {
                            $evidence = $documents[$position === 3 ? 2 : $position];
                            $start = mb_strpos($evidence['page']->text, $evidence['excerpt']);
                            if ($start === false) {
                                throw new RuntimeException('Demo citation absent from extracted page.');
                            }
                            $this->insert('finding_citations', $tenant->id, ['analysis_finding_id' => $finding->id, 'document_id' => $evidence['document']->id, 'document_page_id' => $evidence['page']->id, 'excerpt' => $evidence['excerpt'], 'start_offset' => $start, 'end_offset' => $start + mb_strlen($evidence['excerpt'])]);
                        }
                        if ($index === 1) {
                            $review = $this->insert('finding_reviews', $tenant->id, ['analysis_finding_id' => $finding->id, 'reviewer_id' => $actor->id, 'status' => $status, 'justification' => 'Revisão humana fictícia pré-carregada: evidências e limitações conferidas.', 'notes' => 'Histórico ilustrativo, sem efeito em contratação real.', 'reviewed_at' => now()]);
                            app(AuditLogger::class)->record(new AuditEvent($actor, 'finding.reviewed', 'finding_review', $review->public_id, ['finding_id' => $finding->public_id, 'analysis_id' => $run->public_id, 'status' => $status]));
                        }
                    }
                    if ($index === 1) {
                        $decision = $this->insert('supplier_decisions', $tenant->id, ['supplier_id' => $supplier->id, 'analysis_run_id' => $run->id, 'decided_by' => $actor->id, 'decision' => 'conditional', 'justification' => 'Decisão humana fictícia pré-carregada: condicionada ao plano de continuidade, prazo de retenção e documentação financeira complementar.', 'decided_at' => now()]);
                        app(AuditLogger::class)->record(new AuditEvent($actor, 'supplier.decided', 'supplier_decision', $decision->public_id, ['supplier_id' => $supplier->public_id, 'analysis_id' => $run->public_id, 'requirement_set_id' => $set->public_id, 'decision' => 'conditional']));
                    }
                }
                $tenant->users()->detach();
            } finally {
                $previous === null ? $context->clear() : $context->set($previous);
            }
        }, 3);
    }

    private function insert(string $table, int $tenant, array $attributes): object
    {
        $id = DB::table($table)->insertGetId(['public_id' => (string) Str::uuid(), 'organization_id' => $tenant, 'created_at' => now(), 'updated_at' => now(), ...$attributes]);

        return DB::table($table)->find($id);
    }
}
