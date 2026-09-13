<?php

namespace Tests\Unit\Processor;

use App\Services\Processor\SignedProcessorRequest;
use PHPUnit\Framework\TestCase;

class SignedProcessorRequestTest extends TestCase
{
    public function test_signs_exact_canonical_fixture_bytes_without_reserialization(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 5).'/services/processor/openapi/hmac-test-vector.json'), true, flags: JSON_THROW_ON_ERROR);
        $body = base64_decode($fixture['body_base64'], true);
        $signed = new SignedProcessorRequest($body, $fixture['test_secret'], $fixture['timestamp'], $fixture['nonce']);
        $this->assertSame($fixture['body_utf8'], $signed->body);
        $this->assertSame($fixture['body_sha256'], hash('sha256', $signed->body));
        $this->assertSame(['Content-Type' => 'application/json', 'X-CF-Timestamp' => $fixture['timestamp'], 'X-CF-Nonce' => $fixture['nonce'], 'X-CF-Signature' => $fixture['expected_signature']], $signed->headers);
    }
}
