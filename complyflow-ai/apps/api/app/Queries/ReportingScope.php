<?php

namespace App\Queries;

use App\Models\AnalysisRun;
use App\Models\RequirementSet;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

trait ReportingScope
{
    private function authorizeRead(User $actor, bool $evidence = false): void
    {
        Gate::forUser($actor)->authorize('viewAny', Supplier::class);
        Gate::forUser($actor)->authorize('viewAny', RequirementSet::class);
        Gate::forUser($actor)->authorize('viewAny', AnalysisRun::class);
        abort_if($evidence && ! $actor->hasPermission('document.view'), 403);
    }

    private function publishedSets(int $tenant): Builder
    {
        return DB::table('requirement_sets as rs')
            ->join('requirement_sets as root', 'root.id', '=', DB::raw('COALESCE(rs.parent_id, rs.id)'))
            ->where('rs.organization_id', $tenant)->where('root.organization_id', $tenant)
            ->whereNull('rs.deleted_at')->whereNull('root.deleted_at')->where('rs.status', 'published');
    }

    private function newerPublishedSet(int $tenant): Builder
    {
        return DB::table('requirement_sets as newer_set')->selectRaw('1')
            ->where('newer_set.organization_id', $tenant)->whereNull('newer_set.deleted_at')->where('newer_set.status', 'published')
            ->whereRaw('COALESCE(newer_set.parent_id, newer_set.id) = COALESCE(rs.parent_id, rs.id)')
            ->whereColumn('newer_set.version', '>', 'rs.version');
    }

    private function latestReviews(int $tenant): Builder
    {
        return DB::table('finding_reviews as review')->where('review.organization_id', $tenant)
            ->whereNotExists(function (Builder $query) use ($tenant): void {
                $query->selectRaw('1')->from('finding_reviews as newer_review')->where('newer_review.organization_id', $tenant)
                    ->whereColumn('newer_review.analysis_finding_id', 'review.analysis_finding_id')->whereColumn('newer_review.id', '>', 'review.id');
            });
    }
}
