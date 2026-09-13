<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FindingResource;
use App\Models\AnalysisRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class FindingController extends Controller
{
    public function index(string $analysis)
    {
        abort_unless(Str::isUuid($analysis), 404);
        $run = AnalysisRun::wherePublicIdForCurrentOrganization($analysis)->firstOrFail();
        Gate::authorize('view', $run);
        $findings = DB::table('analysis_findings as f')->join('requirements as r', 'r.id', '=', 'f.requirement_id')
            ->where('f.organization_id', $run->organization_id)->where('f.analysis_run_id', $run->id)
            ->where('r.organization_id', $run->organization_id)->where('r.requirement_set_id', $run->requirement_set_id)
            ->orderBy('r.position')->orderBy('r.code')->orderBy('r.public_id')
            ->get(['f.id', 'f.public_id', 'f.status', 'f.justification', 'f.confidence', 'f.search_summary',
                'r.public_id as requirement_public_id', 'r.code', 'r.title', 'r.category', 'r.weight', 'r.position', 'r.is_required']);
        $citations = DB::table('finding_citations as c')->join('documents as d', 'd.id', '=', 'c.document_id')->join('document_pages as p', 'p.id', '=', 'c.document_page_id')
            ->where('c.organization_id', $run->organization_id)->where('d.organization_id', $run->organization_id)->where('p.organization_id', $run->organization_id)
            ->where('d.supplier_id', $run->supplier_id)->whereIn('d.public_id', $run->document_ids ?? [])->whereColumn('p.document_id', 'd.id')
            ->whereIn('c.analysis_finding_id', $findings->pluck('id'))->orderBy('d.public_id')->orderBy('p.page_number')->orderBy('c.start_offset')->orderBy('c.end_offset')->orderBy('c.public_id')
            ->get(['c.analysis_finding_id', 'c.public_id', 'd.public_id as document_public_id', 'p.page_number', 'c.excerpt', 'c.start_offset', 'c.end_offset'])->groupBy('analysis_finding_id');
        foreach ($findings as $finding) {
            $finding->citations = $citations->get($finding->id, collect());
        }

        return FindingResource::collection($findings);
    }
}
