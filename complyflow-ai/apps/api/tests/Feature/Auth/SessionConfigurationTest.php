<?php

namespace Tests\Feature\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SessionConfigurationTest extends TestCase
{
    #[DataProvider('lifetimes')]
    public function test_effective_idle_lifetime_keeps_the_demo_reachable_despite_legacy_environment(?string $configured, string $expected): void
    {
        $process = new Process([
            PHP_BINARY, '-r',
            'require "vendor/autoload.php"; require "bootstrap/app.php"; $config = require "config/session.php"; echo $config["lifetime"];',
        ], base_path(), ['SESSION_LIFETIME' => $configured ?? false]);
        $process->mustRun();
        $this->assertSame($expected, $process->getOutput());
    }

    public static function lifetimes(): array
    {
        return [
            'unset' => [null, '1440'],
            'legacy two hours' => ['120', '1440'],
            'explicit day' => ['1440', '1440'],
            'operator extends idle window' => ['2880', '2880'],
        ];
    }
}
