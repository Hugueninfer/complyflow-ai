<?php

namespace App\Services\Processor;

use RuntimeException;

class ProcessorException extends RuntimeException
{
    public function __construct(public readonly string $publicCode, public readonly bool $retryable = false)
    {
        // No remote exception, request, response body, secret or document attached.
        parent::__construct('Não foi possível processar a análise.');
    }
}
