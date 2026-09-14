<?php

namespace App\Queries;

use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierComparisonQuery
{
    use ReportingScope;

    public function handle(User $actor, string $leftId, string $rightId, string $setId): array
    {
        $this->authorizeRead($actor, evidence: true);
        $tenant = app(CurrentOrganization::class)->id();
        $left = Supplier::wherePublicIdForCurrentOrganization($leftId)->firstOrFail(['id', 'public_id', 'name']);
        $right = Supplier::wherePublicIdForCurrentOrganization($rightId)->firstOrFail(['id', 'public_id', 'name']);
        if ($left->id === $right->id) {
            throw ValidationException::withMessages(['right' => 'Select two different suppliers.']);
        }
        $set = RequirementSet::wherePublicIdForCurrentOrganization($setId)->firstOrFail(['id', 'public_id', 'name', 'version', 'status']);
        abort_unless($set->status === 'published', 409, 'Select a published checklist.');
        abort_unless($this->publishedSets($tenant)->where('rs.id', $set->id)->exists(), 404);
        $current = $this->publishedSets($tenant)->where('rs.id', $set->id)->whereNotExists($this->newerPublishedSet($tenant))->exists();
        $runs = DB::table('analysis_runs as a')->where('a.organization_id', $tenant)->whereIn('a.supplier_id', [$left->id, $right->id])
            ->where('a.requirement_set_id', $set->id)->where('a.status', 'completed')
            ->whereNotExists(function (Builder $query) use ($tenant): void {
                $query->selectRaw('1')->from('analysis_runs as newer')->where('newer.organization_id', $tenant)
                    ->whereColumn('newer.supplier_id', 'a.supplier_id')->whereColumn('newer.requirement_set_id', 'a.requirement_set_id')
                    ->where('newer.status', 'completed')->whereColumn('newer.id', '>', 'a.id');
            })->select('a.id', 'a.public_id', 'a.supplier_id', 'a.completed_at', 'a.document_ids')
            ->selectSub(DB::table('analysis_runs as last')->selectRaw('MAX(last.id)')->where('last.organization_id', $tenant)->whereColumn('last.supplier_id', 'a.supplier_id'), 'latest_id')
            ->get()->keyBy('supplier_id');
        $requirements = DB::table('requirements')->where('organization_id', $tenant)->where('requirement_set_id', $set->id)
            ->orderBy('position')->orderBy('code')->orderBy('public_id')->get(['id', 'public_id', 'code', 'title', 'category', 'position', 'is_required']);
        $findings = DB::table('analysis_findings')->where('organization_id', $tenant)->whereIn('analysis_run_id', $runs->pluck('id'))->whereIn('requirement_id', $requirements->pluck('id'))
            ->get(['id', 'public_id', 'analysis_run_id', 'requirement_id', 'status', 'justification', 'confidence', 'search_summary']);
        $reviews = $this->latestReviews($tenant)->whereIn('review.analysis_finding_id', $findings->pluck('id'))
            ->get(['review.analysis_finding_id', 'review.public_id', 'review.status', 'review.justification', 'review.notes', 'review.reviewed_at'])->keyBy('analysis_finding_id');
        $evidence = $this->evidence($tenant, $runs, $findings);
        $byRun = $findings->groupBy('analysis_run_id')->map(fn ($group) => $group->keyBy('requirement_id'));
        $side = function (Supplier $supplier) use ($runs): array {
            $run = $runs->get($supplier->id);

            return ['supplier' => ['id' => $supplier->public_id, 'name' => $supplier->name], 'analysis' => $run ? [
                'id' => $run->public_id, 'completed_at' => $this->timestamp($run->completed_at), 'is_latest_for_supplier' => $run->id === $run->latest_id,
            ] : null];
        };
        $cell = function (Supplier $supplier, object $requirement) use ($runs, $byRun, $reviews, $evidence): ?array {
            $run = $runs->get($supplier->id);
            $finding = $run ? $byRun->get($run->id)?->get($requirement->id) : null;
            if (! $finding) {
                return null;
            }
            $review = $reviews->get($finding->id);
            $citations = $evidence->get($finding->id, collect());

            return ['finding_id' => $finding->public_id, 'ai' => [
                'status' => $finding->status, 'justification' => $finding->justification, 'confidence' => (float) $finding->confidence, 'search_summary' => $finding->search_summary,
            ], 'human_review' => $review ? [
                'id' => $review->public_id, 'status' => $review->status, 'justification' => $review->justification, 'note' => $review->notes, 'reviewed_at' => $this->timestamp($review->reviewed_at),
            ] : null, 'requires_human_review' => $review === null, 'evidence' => $citations->map(fn ($citation) => [
                'id' => $citation->public_id, 'document_id' => $citation->document_public_id, 'page_number' => (int) $citation->page_number,
                'quote' => $citation->quote, 'quote_truncated' => (bool) $citation->quote_truncated,
                'start_offset' => (int) $citation->start_offset, 'end_offset' => (int) $citation->end_offset,
            ])->values()->all(), 'evidence_total' => (int) ($citations->first()?->evidence_total ?? 0)];
        };

        return ['requirement_set' => ['id' => $set->public_id, 'name' => $set->name, 'version' => $set->version, 'is_current' => $current],
            'left' => $side($left), 'right' => $side($right), 'rows' => $requirements->map(fn ($requirement) => [
                'requirement' => ['id' => $requirement->public_id, 'code' => $requirement->code, 'title' => $requirement->title, 'category' => $requirement->category, 'position' => (int) $requirement->position, 'is_required' => (bool) $requirement->is_required],
                'left' => $cell($left, $requirement), 'right' => $cell($right, $requirement),
            ])->all()];
    }

    private function evidence(int $tenant, Collection $runs, Collection $findings): Collection
    {
        if ($runs->isEmpty() || $findings->isEmpty()) {
            return collect();
        }
        $citations = DB::table('finding_citations as c')->join('analysis_findings as f', 'f.id', '=', 'c.analysis_finding_id')
            ->join('documents as d', 'd.id', '=', 'c.document_id')->join('document_pages as p', 'p.id', '=', 'c.document_page_id')
            ->where('c.organization_id', $tenant)->where('f.organization_id', $tenant)->where('d.organization_id', $tenant)->where('p.organization_id', $tenant)
            ->whereNull('d.deleted_at')->whereColumn('p.document_id', 'd.id')->whereIn('f.id', $findings->pluck('id'))
            ->where(function (Builder $query) use ($runs): void {
                // At most two alternatives; each binds its own supplier and immutable document snapshot.
                foreach ($runs as $run) {
                    $query->orWhere(fn (Builder $side) => $side->where('f.analysis_run_id', $run->id)->where('d.supplier_id', $run->supplier_id)
                        ->whereIn('d.public_id', json_decode($run->document_ids ?? '[]', true) ?? []));
                }
            })
            ->select('c.analysis_finding_id', 'c.public_id', 'd.public_id as document_public_id', 'p.page_number', 'c.start_offset', 'c.end_offset')
            ->selectRaw('SUBSTR(c.excerpt, 1, 240) as quote, LENGTH(c.excerpt) > 240 as quote_truncated')
            ->selectRaw('COUNT(*) OVER (PARTITION BY c.analysis_finding_id) as evidence_total')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY c.analysis_finding_id ORDER BY d.public_id, p.page_number, c.start_offset, c.end_offset, c.public_id) as evidence_position');

        return DB::query()->fromSub($citations, 'evidence')->where('evidence_position', '<=', 3)->orderBy('analysis_finding_id')->orderBy('evidence_position')
            ->get()->groupBy('analysis_finding_id');
    }

    private function timestamp(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->toIso8601String();
    }
}
