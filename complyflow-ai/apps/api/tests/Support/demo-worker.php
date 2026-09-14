<?php

use App\Services\Demo\CreateDemoSession;
use Database\Seeders\DemoTemplateSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("SET application_name = '".$argv[2]."'");
DB::transaction(function () use ($argv): void {
    if ($argv[1] === 'seed') {
        app(DemoTemplateSeeder::class)->run();
        $id = DB::table('organizations')->where('slug', 'demo-template')->value('public_id');
    } else {
        $id = app(CreateDemoSession::class)->handle()->public_id;
    }
    if (($argv[3] ?? '') === 'pause') {
        echo "ready\n";
        flush();
        fgets(STDIN);
    }
    echo json_encode(['id' => $id], JSON_THROW_ON_ERROR)."\n";
});
