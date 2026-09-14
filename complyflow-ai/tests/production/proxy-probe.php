<?php

// Test-only front controller, mounted into a disposable container. Never copied into production.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Route::middleware('web')->get('/api/proxy-probe', function (Illuminate\Http\Request $request) {
    $request->session()->put('probe', true);

    return response()->json([
        'secure' => $request->isSecure(),
        'origin' => $request->getSchemeAndHttpHost(),
        'login_url' => url('/login'),
        'forwarded' => $request->header('Forwarded'),
        'forwarded_for' => $request->header('X-Forwarded-For'),
        'forwarded_host' => $request->header('X-Forwarded-Host'),
        'forwarded_proto' => $request->header('X-Forwarded-Proto'),
        'ip' => $request->ip(),
    ]);
});
$app->handleRequest(Illuminate\Http\Request::capture());
