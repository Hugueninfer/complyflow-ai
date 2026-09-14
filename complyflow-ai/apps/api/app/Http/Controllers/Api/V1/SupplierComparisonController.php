<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Queries\SupplierComparisonQuery;
use Illuminate\Http\Request;

class SupplierComparisonController extends Controller
{
    public function __invoke(Request $request, SupplierComparisonQuery $query)
    {
        $input = $request->validate(['left' => ['required', 'uuid'], 'right' => ['required', 'uuid', 'different:left'], 'requirement_set' => ['required', 'uuid']]);

        return response()->json(['data' => $query->handle($request->user(), $input['left'], $input['right'], $input['requirement_set'])]);
    }
}
