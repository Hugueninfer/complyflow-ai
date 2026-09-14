<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PublicAccessLimits
{
    public static function identity(Request $request): string
    {
        $email = $request->input('email');
        $normalized = is_string($email) ? Str::lower(trim($email)) : '';

        // Malformed requests must not put unrelated anonymous visitors in one shared bucket.
        if ($normalized === '') {
            return 'anonymous:'.self::session($request);
        }

        return hash_hmac('sha256', $normalized, config('app.key'));
    }

    public static function session(Request $request): string
    {
        // Kept through session regeneration; never read from request input or forwarded headers.
        $session = $request->session();
        if (! $session->has('public_rate_limit_nonce')) {
            $session->put('public_rate_limit_nonce', bin2hex(random_bytes(32)));
        }

        return $session->get('public_rate_limit_nonce');
    }
}
