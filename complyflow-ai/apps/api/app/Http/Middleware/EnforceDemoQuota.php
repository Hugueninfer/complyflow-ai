<?php

namespace App\Http\Middleware;

use App\Models\DemoSession;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceDemoQuota
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $demoSession = DemoSession::query()
            ->where('user_id', $request->user()?->getKey())
            ->first();

        if ($demoSession === null) {
            return $next($request);
        }

        if ($demoSession->expires_at->lessThanOrEqualTo(now())) {
            return new JsonResponse(['message' => 'Demo session expired.'], 401);
        }

        $exceeded = match ($resource) {
            'suppliers' => $demoSession->suppliers_used >= $demoSession->supplier_quota,
            'analyses' => $demoSession->analyses_used >= $demoSession->analysis_quota,
            'storage' => $demoSession->storage_used_bytes + $this->uploadedBytes($request)
                > $demoSession->storage_quota_bytes,
            default => false,
        };

        if ($exceeded) {
            return new JsonResponse(['message' => 'Demo quota exceeded.'], 429);
        }

        return $next($request);
    }

    private function uploadedBytes(Request $request): int
    {
        $file = $request->file('document');

        return $file === null ? 1 : (int) $file->getSize();
    }
}
