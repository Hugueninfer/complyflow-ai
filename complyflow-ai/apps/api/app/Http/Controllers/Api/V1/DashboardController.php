<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Queries\DashboardQuery;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardQuery $query)
    {
        return response()->json(['data' => $query->handle($request->user())]);
    }
}
