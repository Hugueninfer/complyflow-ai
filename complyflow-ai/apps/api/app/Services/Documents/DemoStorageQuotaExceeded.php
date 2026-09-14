<?php

namespace App\Services\Documents;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DemoStorageQuotaExceeded extends HttpException
{
    public function __construct()
    {
        parent::__construct(413, 'Demo storage quota exceeded.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'demo_storage_quota_exceeded',
            'message' => $this->getMessage(),
        ], 413);
    }
}
