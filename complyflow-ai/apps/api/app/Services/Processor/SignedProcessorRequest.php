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
        return Http::connectTimeout(5)->timeout(60)->withoutRedirecting()->withHeaders($this->headers)
            ->withBody($this->body, 'application/json')->post($url);
    }
}
