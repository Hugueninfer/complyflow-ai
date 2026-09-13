<?php

// Pipe this actual Laravel HTTP request into the Python contract verifier.
use App\Services\Processor\SignedProcessorRequest;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$fixture = json_decode(file_get_contents(dirname(__DIR__, 4).'/services/processor/openapi/hmac-test-vector.json'), true, flags: JSON_THROW_ON_ERROR);
$request = new SignedProcessorRequest(base64_decode($fixture['body_base64'], true), $fixture['test_secret'], $fixture['timestamp'], $fixture['nonce']);
Http::fake(function ($wire) {
    echo json_encode(['body_base64' => base64_encode($wire->body()), 'headers' => $wire->headers()], JSON_THROW_ON_ERROR)."\n";

    return Http::response([], 200);
});
$request->send('http://processor:8001/v1/analyze');
