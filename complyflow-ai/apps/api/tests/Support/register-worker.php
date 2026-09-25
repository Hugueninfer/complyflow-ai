<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$app->instance('env', 'testing');
config(['session.driver' => 'array', 'cache.default' => 'array', 'app.debug' => false]);
User::creating(function (): void {
    echo "ready\n";
    flush();
    fgets(STDIN);
});
$request = Request::create('/api/v1/register', 'POST', server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: json_encode([
    'name' => 'Race owner', 'email' => $argv[1], 'organization_name' => 'Race tenant',
    'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
]));
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)])."\n";
$kernel->terminate($request, $response);
