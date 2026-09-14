<?php

namespace App\Queries;

use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class DashboardQuery
{
    use ReportingScope;

    public function handle(User $actor): array
    {
        $this->authorizeRead($actor);
        $tenant = app(CurrentOrganization::class)->id();
        $sets = $this->publishedSets($tenant)->whereNotExists($this->newerPublishedSet($tenant))->select('rs.id');
        $reviews = $this->latestReviews($tenant)->select('review.id', 'review.analysis_finding_id', 'review.status');
        $rows = DB::table('analysis_runs as a')
            ->join('suppliers as s', 's.id', '=', 'a.supplier_id')->joinSub($sets, 'current_set', 'current_set.id', '=', 'a.requirement_set_id')
            ->where('a.organization_id', $tenant)->where('s.organization_id', $tenant)->whereNull('s.deleted_at')->where('a.status', 'completed')
            ->whereNotExists(function (Builder $query) use ($tenant): void {
                $query->selectRaw('1')->from('analysis_runs as newer')->where('newer.organization_id', $tenant)
                    ->whereColumn('newer.supplier_id', 'a.supplier_id')->whereColumn('newer.id', '>', 'a.id');
            })
            ->leftJoin('requirements as r', fn (JoinClause $join) => $join->on('r.requirement_set_id', '=', 'a.requirement_set_id')->where('r.organization_id', $tenant))
            ->leftJoin('analysis_findings as f', fn (JoinClause $join) => $join->on('f.analysis_run_id', '=', 'a.id')->on('f.requirement_id', '=', 'r.id')->where('f.organization_id', $tenant))
            ->leftJoinSub($reviews, 'v', 'v.analysis_finding_id', '=', 'f.id')
            ->leftJoin('supplier_decisions as d', fn (JoinClause $join) => $join->on('d.analysis_run_id', '=', 'a.id')->on('d.supplier_id', '=', 's.id')->where('d.organization_id', $tenant));

        $counts = $rows->selectRaw('COUNT(DISTINCT a.supplier_id) as suppliers_analyzed')
            ->selectRaw("COALESCE(SUM(CASE WHEN r.id IS NOT NULL AND COALESCE(v.status, f.status) = 'met' THEN 1 ELSE 0 END), 0) as requirements_met")
            ->selectRaw("COALESCE(SUM(CASE WHEN r.id IS NOT NULL AND (f.id IS NULL OR COALESCE(v.status, f.status) <> 'met') THEN 1 ELSE 0 END), 0) as pending_requirements")
            ->selectRaw('COUNT(DISTINCT CASE WHEN r.is_required = ? AND v.id IS NULL AND d.id IS NULL THEN a.id END) as analyses_awaiting_review', [true])->first();

        return array_map(intval(...), (array) $counts);
    }
}
