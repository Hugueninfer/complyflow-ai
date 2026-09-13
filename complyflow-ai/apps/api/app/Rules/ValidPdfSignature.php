<?php

namespace App\Rules;

use Closure;
use finfo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ValidPdfSignature implements ValidationRule
{
    public const MAX_BYTES = 5242880;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('A valid PDF upload is required.');

            return;
        }

        $path = $value->getRealPath();
        abort_if(filesize($path) > self::MAX_BYTES, 413, 'PDF exceeds the 5 MiB limit.');

        if ((new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf') {
            $fail('The file must be a PDF.');

            return;
        }

        $stream = fopen($path, 'rb');
        try {
            if (fread($stream, 5) !== '%PDF-') {
                $fail('The file must start with a PDF signature.');
            }
        } finally {
            fclose($stream);
        }
    }
}
