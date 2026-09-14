<?php

namespace App\Services\Processor;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final readonly class SignedProcessorRequest
{
    public array $headers;

    public function __construct(public string $body, string $secret, string $timestamp, string $nonce)
    {
        $message = $timestamp."\n".$nonce."\n".hash('sha256', $body);
        $this->headers = ['Content-Type' => 'application/json', 'X-CF-Timestamp' => $timestamp, 'X-CF-Nonce' => $nonce,
            'X-CF-Signature' => hash_hmac('sha256', $message, $secret)];
    }

    public function send(string $url): Response
    {
        $rawBudget = config('services.processor.analysis_timeout_seconds');
        $rawTimeout = config('services.processor.http_timeout_seconds');
        if (! is_numeric($rawBudget) || ! is_numeric($rawTimeout)) {
            throw new ProcessorException('processor_not_configured');
        }
        $budget = (float) $rawBudget;
        $timeout = (float) $rawTimeout;
        // Python may only shorten its 45 s budget. Reserve 15 s for request/
        // response transport and cleanup, then another 15 s before the 75 s job.
        if (! is_finite($budget) || ! is_finite($timeout) || $budget <= 0 || $budget > 45
            || $timeout < $budget + 15 || $timeout > 60) {
            throw new ProcessorException('processor_not_configured');
        }

        return Http::connectTimeout(5)->timeout($timeout)->withoutRedirecting()->withHeaders($this->headers)
            ->withBody($this->body, 'application/json')->post($url);
    }
}
