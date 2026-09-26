<?php

namespace App\Services\Analysis;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiDailyQuotaExceeded extends HttpException
{
    public function __construct()
    {
        parent::__construct(429, 'Daily AI analysis quota exceeded.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'ai_daily_quota_exceeded',
            'message' => $this->getMessage(),
        ], 429);
    }
}
