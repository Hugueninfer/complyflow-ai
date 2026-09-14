<?php

namespace App\Services\Demo;

use App\Models\DemoSession;
use App\Models\Organization;
use App\Models\RequirementSet;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Analysis\AnalysisFingerprint;
use App\Services\Audit\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateDemoSession
{
    private const TEMPLATE_SLUG = 'demo-template';

    private const SUPPLIER_QUOTA = 10;

    private const ANALYSIS_QUOTA = 3;

    private const STORAGE_QUOTA_BYTES = 15 * 1024 * 1024;

    public function handle(): DemoSession
    {
        return DB::transaction(function (): DemoSession {
            $organization = Organization::query()->create([
                'name' => 'ComplyFlow Demo',
                'slug' => 'demo-'.Str::lower(Str::uuid()->toString()),
            ]);
            $user = User::query()->create([
                'name' => 'Demo Reviewer',
                'email' => 'demo-'.Str::lower(Str::uuid()->toString()).'@example.invalid',
                'password' => Str::random(40),
            ]);
            $reviewerRole = Role::query()->where('name', 'reviewer')->firstOrFail();

            $organization->users()->attach($user, ['role_id' => $reviewerRole->id]);

            $template = Organization::query()->where('slug', self::TEMPLATE_SLUG)->sharedLock()->first();

            if ($template !== null) {
                $context = app(CurrentOrganization::class);
                $previous = $context->has() ? $context->id() : null;
                $context->set($organization);
                try {
                    $this->cloneTemplate($template, $organization, $user);
                } finally {
                    $previous === null ? $context->clear() : $context->set($previous);
                }
            }

            $usage = $this->usageFor($organization);
            $this->ensureWithinQuota($usage);

            $demoSession = DemoSession::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'token_hash' => hash('sha256', Str::random(64)),
                'supplier_quota' => self::SUPPLIER_QUOTA,
                'analysis_quota' => self::ANALYSIS_QUOTA,
                'storage_quota_bytes' => self::STORAGE_QUOTA_BYTES,
                'suppliers_used' => $usage['suppliers'],
                'analyses_used' => $usage['analyses'],
                'storage_used_bytes' => $usage['storage_bytes'],
                'expires_at' => now()->addHours(24),
            ]);

            return $demoSession->load(['organization', 'user']);
        });
    }

    private function cloneTemplate(Organization $template, Organization $organization, User $user): void
    {
        $supplierIds = [];

        foreach (DB::table('suppliers')->where('organization_id', $template->id)->whereNull('deleted_at')->get() as $source) {
            $attributes = $this->baseAttributes($source, $organization);
            $supplierIds[$source->id] = DB::table('suppliers')->insertGetId($attributes);
        }

        $requirementSetIds = [];
        $requirementSets = DB::table('requirement_sets')
            ->where('organization_id', $template->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($requirementSets as $source) {
            $attributes = $this->baseAttributes($source, $organization);
            $attributes['parent_id'] = null;
            $requirementSetIds[$source->id] = DB::table('requirement_sets')->insertGetId($attributes);
        }

        foreach ($requirementSets as $source) {
            if ($source->parent_id !== null && isset($requirementSetIds[$source->parent_id])) {
                DB::table('requirement_sets')->where('id', $requirementSetIds[$source->id])->update([
                    'parent_id' => $requirementSetIds[$source->parent_id],
                ]);
            }
        }

        $requirementIds = [];

        foreach (DB::table('requirements')->where('organization_id', $template->id)->orderBy('id')->get() as $source) {
            if (! isset($requirementSetIds[$source->requirement_set_id])) {
                continue;
            }

            $attributes = $this->baseAttributes($source, $organization);
            $attributes['requirement_set_id'] = $requirementSetIds[$source->requirement_set_id];

            $requirementIds[$source->id] = DB::table('requirements')->insertGetId($attributes);
        }

        $documentIds = [];
        $documentPublicIds = [];

        foreach (DB::table('documents')->where('organization_id', $template->id)->whereNull('deleted_at')->orderBy('id')->get() as $source) {
            if (! isset($supplierIds[$source->supplier_id])) {
                continue;
            }

            $attributes = $this->baseAttributes($source, $organization);
            $attributes['supplier_id'] = $supplierIds[$source->supplier_id];
            $attributes['storage_name'] = Str::uuid()->toString().$this->extension($source->storage_name);
            $documentIds[$source->id] = DB::table('documents')->insertGetId($attributes);
            $documentPublicIds[$source->public_id] = $attributes['public_id'];
        }

        foreach (DB::table('document_blobs')->where('organization_id', $template->id)->orderBy('id')->get() as $source) {
            if (! isset($documentIds[$source->document_id])) {
                continue;
            }

            $attributes = $this->baseAttributes($source, $organization);
            $attributes['document_id'] = $documentIds[$source->document_id];
            DB::table('document_blobs')->insert($attributes);
        }

        $pageIds = [];

        foreach (DB::table('document_pages')->where('organization_id', $template->id)->orderBy('id')->get() as $source) {
            if (! isset($documentIds[$source->document_id])) {
                continue;
            }

            $attributes = $this->baseAttributes($source, $organization);
            $attributes['document_id'] = $documentIds[$source->document_id];
            $pageIds[$source->id] = DB::table('document_pages')->insertGetId($attributes);
        }

        foreach (DB::table('document_chunks')->where('organization_id', $template->id)->orderBy('id')->get() as $source) {
            if (! isset($documentIds[$source->document_id], $pageIds[$source->document_page_id])) {
                continue;
            }

            $attributes = $this->baseAttributes($source, $organization);
            $attributes['document_id'] = $documentIds[$source->document_id];
            $attributes['document_page_id'] = $pageIds[$source->document_page_id];
            DB::table('document_chunks')->insert($attributes);
        }

        $analysisRunIds = [];

        foreach (DB::table('analysis_runs')->where('organization_id', $template->id)->orderBy('id')->get() as $source) {
            if (! isset($supplierIds[$source->supplier_id], $requirementSetIds[$source->requirement_set_id])) {
                continue;
            }

            $attributes = $this->baseAttributes($source, $organization);
            $attributes['supplier_id'] = $supplierIds[$source->supplier_id];
            $attributes['requirement_set_id'] = $requirementSetIds[$source->requirement_set_id];
            if ($source->document_ids !== null) {
                $attributes['document_ids'] = json_encode(array_map(
                    fn (string $id) => $documentPublicIds[$id] ?? throw new \RuntimeException('Demo analysis references a missing document.'),
                    json_decode($source->document_ids, true, flags: JSON_THROW_ON_ERROR),
                ), JSON_THROW_ON_ERROR);
                $documents = DB::table('documents')->where('organization_id', $organization->id)->whereIn('public_id', json_decode($attributes['document_ids'], true))->get();
                $attributes['document_set_hash'] = AnalysisFingerprint::make(Supplier::findOrFail($attributes['supplier_id']), RequirementSet::findOrFail($attributes['requirement_set_id']), $documents->pluck('sha256')->all());
            }
            $attributes['idempotency_key'] = 'demo-clone:'.Str::uuid();
            $attributes['owner_message_uuid'] = null;
            $attributes['owner_reservation_id'] = null;
            $attributes['owner_reservation_attempt'] = null;
            $analysisRunIds[$source->id] = DB::table('analysis_runs')->insertGetId($attributes);
        }

        $findingIds = [];

        foreach (DB::table('analysis_findings')->where('organization_id', $template->id)->orderBy('id')->get() as $source) {
            if (! isset($analysisRunIds[$source->analysis_run_id], $requirementIds[$source->requirement_id])) {
                continue;
            }

            $attributes = $this->baseAttributes($source, $organization);
            $attributes['analysis_run_id'] = $analysisRunIds[$source->analysis_run_id];
            $attributes['requirement_id'] = $requirementIds[$source->requirement_id];
            $findingIds[$source->id] = DB::table('analysis_findings')->insertGetId($attributes);
        }

        foreach (DB::table('finding_citations')->where('organization_id', $template->id)->orderBy('id')->get() as $source) {
            if (! isset(
                $findingIds[$source->analysis_finding_id],
                $documentIds[$source->document_id],
                $pageIds[$source->document_page_id],
            )) {
                continue;
            }

            $attributes = $this->baseAttributes($source, $organization);
            $attributes['analysis_finding_id'] = $findingIds[$source->analysis_finding_id];
            $attributes['document_id'] = $documentIds[$source->document_id];
            $attributes['document_page_id'] = $pageIds[$source->document_page_id];
            DB::table('finding_citations')->insert($attributes);
        }

        foreach (DB::table('finding_reviews')->where('organization_id', $template->id)->orderBy('id')->get() as $source) {
            if (! isset($findingIds[$source->analysis_finding_id])) {
                continue;
            }

            $attributes = $this->baseAttributes($source, $organization);
            $attributes['analysis_finding_id'] = $findingIds[$source->analysis_finding_id];
            $attributes['reviewer_id'] = $user->id;
            $attributes['reviewed_at'] = now();
            $attributes['idempotency_key'] = null;
            $attributes['request_hash'] = null;
            DB::table('finding_reviews')->insert($attributes);
            $finding = DB::table('analysis_findings')->find($attributes['analysis_finding_id']);
            $run = DB::table('analysis_runs')->find($finding->analysis_run_id);
            app(AuditLogger::class)->record(new AuditEvent($user, 'finding.reviewed', 'finding_review', $attributes['public_id'], [
                'finding_id' => $finding->public_id, 'analysis_id' => $run->public_id, 'status' => $attributes['status'],
            ]));
        }

        foreach (DB::table('supplier_decisions')->where('organization_id', $template->id)->orderBy('id')->get() as $source) {
            if (! isset($supplierIds[$source->supplier_id])) {
                continue;
            }

            if ($source->analysis_run_id !== null && ! isset($analysisRunIds[$source->analysis_run_id])) {
                continue;
            }

            $attributes = $this->baseAttributes($source, $organization);
            $attributes['supplier_id'] = $supplierIds[$source->supplier_id];
            $attributes['analysis_run_id'] = $source->analysis_run_id === null
                ? null
                : $analysisRunIds[$source->analysis_run_id];
            $attributes['decided_by'] = $user->id;
            $attributes['decided_at'] = now();
            $attributes['idempotency_key'] = null;
            $attributes['request_hash'] = null;
            DB::table('supplier_decisions')->insert($attributes);
            if ($attributes['analysis_run_id'] !== null) {
                $run = DB::table('analysis_runs')->find($attributes['analysis_run_id']);
                app(AuditLogger::class)->record(new AuditEvent($user, 'supplier.decided', 'supplier_decision', $attributes['public_id'], [
                    'supplier_id' => DB::table('suppliers')->where('id', $attributes['supplier_id'])->value('public_id'),
                    'analysis_id' => $run->public_id,
                    'requirement_set_id' => DB::table('requirement_sets')->where('id', $run->requirement_set_id)->value('public_id'),
                    'decision' => $attributes['decision'],
                ]));
            }
        }
    }

    private function extension(string $storageName): string
    {
        $extension = pathinfo($storageName, PATHINFO_EXTENSION);

        return $extension === '' ? '' : '.'.$extension;
    }

    /**
     * @return array{suppliers: int, analyses: int, storage_bytes: int}
     */
    private function usageFor(Organization $organization): array
    {
        return [
            'suppliers' => DB::table('suppliers')->where('organization_id', $organization->id)->count(),
            'analyses' => DB::table('analysis_runs')->where('organization_id', $organization->id)->count(),
            'storage_bytes' => (int) DB::table('documents')
                ->where('organization_id', $organization->id)
                ->sum('size_bytes'),
        ];
    }

    /**
     * @param  array{suppliers: int, analyses: int, storage_bytes: int}  $usage
     */
    private function ensureWithinQuota(array $usage): void
    {
        if (
            $usage['suppliers'] > self::SUPPLIER_QUOTA
            || $usage['analyses'] > self::ANALYSIS_QUOTA
            || $usage['storage_bytes'] > self::STORAGE_QUOTA_BYTES
        ) {
            throw new DemoTemplateQuotaExceeded('Demo template exceeds quota.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseAttributes(object $source, Organization $organization): array
    {
        $attributes = (array) $source;
        unset($attributes['id']);

        $attributes['public_id'] = Str::uuid()->toString();
        $attributes['organization_id'] = $organization->id;
        $attributes['created_at'] = now();
        $attributes['updated_at'] = now();

        return $attributes;
    }
}
