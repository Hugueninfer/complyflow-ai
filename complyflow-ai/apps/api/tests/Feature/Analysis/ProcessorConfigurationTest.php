<?php

namespace Tests\Feature\Analysis;

use App\Services\Processor\ProcessorException;
use App\Services\Processor\SignedProcessorRequest;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProcessorConfigurationTest extends TestCase
{
    public static function invalidRawTimeouts(): array
    {
        return [
            ['PROCESSOR_HTTP_TIMEOUT_SECONDS', '60garbage'],
            ['PROCESSOR_ANALYSIS_TIMEOUT_SECONDS', '45garbage'],
            ['PROCESSOR_HTTP_TIMEOUT_SECONDS', 'true'],
            ['PROCESSOR_ANALYSIS_TIMEOUT_SECONDS', 'true'],
            ['PROCESSOR_HTTP_TIMEOUT_SECONDS', '60 seconds'],
            ['PROCESSOR_ANALYSIS_TIMEOUT_SECONDS', '4.5.0'],
        ];
    }

    #[DataProvider('invalidRawTimeouts')]
    public function test_invalid_raw_environment_value_is_rejected_before_cast(string $name, string $value): void
    {
        $previous = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv($name.'='.$value);
        try {
            $this->assertSame($value, Env::getRepository()->get($name));
            $services = require config_path('services.php');
            config(['services.processor' => $services['processor']]);
        } finally {
            foreach ([0 => '_ENV', 1 => '_SERVER'] as $index => $global) {
                if ($previous[$index] === null) {
                    unset($GLOBALS[$global][$name]);
                } else {
                    $GLOBALS[$global][$name] = $previous[$index];
                }
            }
            putenv($previous[2] === false ? $name : $name.'='.$previous[2]);
        }
        Http::fake();
        try {
            (new SignedProcessorRequest('{}', 'test-only', '1', 'nonce'))->send('http://processor:8001/v1/analyze');
            $this->fail('Malformed environment configuration must not become a numeric prefix.');
        } catch (ProcessorException $error) {
            $this->assertSame('processor_not_configured', $error->publicCode);
        }
        Http::assertNothingSent();
    }
}
