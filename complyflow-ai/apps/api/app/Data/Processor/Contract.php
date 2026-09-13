<?php

namespace App\Data\Processor;

use App\Services\Processor\ProcessorException;
use Illuminate\Support\Str;

final class Contract
{
    public static function check(bool $condition): void
    {
        if (! $condition) {
            throw new ProcessorException('invalid_processor_result');
        }
    }

    public static function object(mixed $value, array $keys): array
    {
        self::check(is_array($value));
        self::check(count($value) === count($keys) && array_diff($keys, array_keys($value)) === []);

        return $value;
    }

    public static function list(mixed $value): array
    {
        self::check(is_array($value) && array_is_list($value));

        return $value;
    }

    public static function uuid(mixed $value): string
    {
        self::check(is_string($value) && Str::isUuid($value));

        return strtolower($value);
    }

    public static function text(mixed $value, bool $blank = false): string
    {
        self::check(is_string($value) && mb_check_encoding($value, 'UTF-8') && ($blank || preg_match('/\S/u', $value) === 1));

        return $value;
    }

    public static function integer(mixed $value, int $minimum = 0): int
    {
        self::check(is_int($value) && $value >= $minimum);

        return $value;
    }

    public static function number(mixed $value): float
    {
        self::check((is_int($value) || is_float($value)) && is_finite((float) $value));

        return (float) $value;
    }

    public static function excerpt(string $text, string $quote, int $start, int $end): void
    {
        self::check($end >= $start && $end <= mb_strlen($text, 'UTF-8') && mb_substr($text, $start, $end - $start, 'UTF-8') === $quote);
    }
}
