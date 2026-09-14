<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['laravel' => 'ready', 'database' => 'unavailable', 'processor' => 'unavailable'];
        try {
            DB::select('select 1');
            $checks['database'] = 'ready';
        } catch (Throwable) {
            // Public readiness never includes connection strings or exceptions.
        }
        try {
            $response = Http::connectTimeout(1)->timeout(2)
                ->get(rtrim(config('services.processor.url'), '/').'/health');
            if ($response->successful() && $response->json('status') === 'ready') {
                $checks['processor'] = 'ready';
            }
        } catch (Throwable) {
        }
        $ready = ! in_array('unavailable', $checks, true);

        return response()->json(['status' => $ready ? 'ready' : 'unavailable', 'checks' => $checks], $ready ? 200 : 503);
    }
}
