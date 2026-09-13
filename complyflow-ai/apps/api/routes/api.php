<?php

use App\Http\Controllers\Api\V1\AnalysisController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DemoSessionController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\FindingController;
use App\Http\Controllers\Api\V1\FindingReviewController;
use App\Http\Controllers\Api\V1\RequirementSetController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierDecisionController;
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

    Route::middleware(['auth:sanctum', 'organization'])->group(function (): void {
        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('demo.quota:suppliers');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
        Route::post('/suppliers/{supplier}/documents', [DocumentController::class, 'store']);
        Route::get('/documents/{document}', [DocumentController::class, 'show']);
        Route::post('/suppliers/{supplier}/analyses', [AnalysisController::class, 'store'])->middleware('throttle:30,1');
        Route::get('/analyses/{analysis}', [AnalysisController::class, 'show']);
        Route::get('/analyses/{analysis}/findings', [FindingController::class, 'index']);
        Route::post('/findings/{finding}/reviews', [FindingReviewController::class, 'store']);
        Route::post('/suppliers/{supplier}/decisions', [SupplierDecisionController::class, 'store']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        Route::get('/requirement-sets', [RequirementSetController::class, 'index']);
        Route::post('/requirement-sets', [RequirementSetController::class, 'store']);
        Route::get('/requirement-sets/{requirementSet}', [RequirementSetController::class, 'show']);
        Route::put('/requirement-sets/{requirementSet}', [RequirementSetController::class, 'update']);
        Route::patch('/requirement-sets/{requirementSet}', [RequirementSetController::class, 'update']);
        Route::delete('/requirement-sets/{requirementSet}', [RequirementSetController::class, 'destroy']);
        Route::post('/requirement-sets/{requirementSet}/publish', [RequirementSetController::class, 'publish']);
        Route::post('/requirement-sets/{requirementSet}/versions', [RequirementSetController::class, 'createVersion']);
    });
});
