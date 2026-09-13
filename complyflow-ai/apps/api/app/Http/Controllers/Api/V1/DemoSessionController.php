<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Demo\CreateDemoSession;
use App\Services\Demo\DemoTemplateQuotaExceeded;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DemoSessionController extends Controller
{
    public function store(Request $request, CreateDemoSession $createDemoSession): JsonResponse
    {
        try {
            $demoSession = $createDemoSession->handle();
        } catch (DemoTemplateQuotaExceeded) {
            return response()->json(['message' => 'Demo template exceeds quota.'], 503);
        }

        Auth::guard('web')->login($demoSession->user);
        $request->session()->regenerate();

        return response()->json([
            'data' => [
                'id' => $demoSession->public_id,
                'organization_id' => $demoSession->organization->public_id,
                'expires_at' => $demoSession->expires_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'quotas' => [
                    'suppliers' => $demoSession->supplier_quota,
                    'analyses' => $demoSession->analysis_quota,
                    'storage_bytes' => $demoSession->storage_quota_bytes,
                ],
            ],
        ], 201);
    }
}
