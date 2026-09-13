<?php

namespace App\Services\Demo;

use App\Models\DemoSession;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateDemoSession
{
    private const TEMPLATE_SLUG = 'demo-template';

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

            $template = Organization::query()->where('slug', self::TEMPLATE_SLUG)->first();

            if ($template !== null) {
                $this->cloneTemplate($template, $organization, $user);
            }

            $demoSession = DemoSession::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'token_hash' => hash('sha256', Str::random(64)),
                'supplier_quota' => 10,
                'analysis_quota' => 3,
                'storage_quota_bytes' => 15 * 1024 * 1024,
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

        foreach (DB::table('documents')->where('organization_id', $template->id)->whereNull('deleted_at')->orderBy('id')->get() as $source) {
            if (! isset($supplierIds[$source->supplier_id])) {
                continue;
            }

            $attributes = $this->baseAttributes($source, $organization);
            $attributes['supplier_id'] = $supplierIds[$source->supplier_id];
            $attributes['storage_name'] = Str::uuid()->toString().$this->extension($source->storage_name);
            $documentIds[$source->id] = DB::table('documents')->insertGetId($attributes);
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
            DB::table('finding_reviews')->insert($attributes);
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
            DB::table('supplier_decisions')->insert($attributes);
        }
    }

    private function extension(string $storageName): string
    {
        $extension = pathinfo($storageName, PATHINFO_EXTENSION);

        return $extension === '' ? '' : '.'.$extension;
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
