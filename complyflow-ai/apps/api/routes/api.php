<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DemoSessionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    DB::select('select 1');

    return response()->json(['status' => 'ready']);
});

Route::middleware('web')->prefix('v1')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/demo-sessions', [DemoSessionController::class, 'store']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});
