<?php

declare(strict_types=1);

namespace Trk\Monitor\Support;

/**
 * Recursively redacts sensitive values before anything leaves the application.
 *
 * A key is sensitive when its normalized form (lower-case, without "-", "_" and spaces)
 * contains any configured sensitive field, unless it is explicitly allow-listed.
 * Values that look like credentials (bearer tokens, card numbers) are redacted as well.
 */
final class Sanitizer
{
    public const REDACTED = '[Filtered]';

    /** @var list<string> */
    private array $sensitive;

    /** @var list<string> */
    private array $allowed;

    /**
     * @param  list<string>  $sensitiveFields
     * @param  list<string>  $allowedFields
     */
    public function __construct(array $sensitiveFields, array $allowedFields = [], private readonly int $maxDepth = 10)
    {
        $this->sensitive = array_values(array_filter(array_map([self::class, 'normalize'], $sensitiveFields)));
        $this->allowed = array_map([self::class, 'normalize'], $allowedFields);
    }

    public function isSensitiveKey(int|string $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        $normalized = self::normalize($key);

        if ($normalized === '' || in_array($normalized, $this->allowed, true)) {
            return false;
        }

        foreach ($this->sensitive as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > $this->maxDepth) {
            return '[Max depth]';
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->isSensitiveKey($key) ? self::REDACTED : $this->sanitize($item, $depth + 1);
            }

            return $out;
        }

        if (is_object($value)) {
            if ($value instanceof \JsonSerializable) {
                return $this->sanitize($value->jsonSerialize(), $depth + 1);
            }

            if ($value instanceof \Stringable) {
                return $this->sanitizeString((string) $value);
            }

            return '['.get_class($value).']';
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        if (is_resource($value)) {
            return '[resource]';
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $headers
     * @return array<string, string>
     */
    public function sanitizeHeaders(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $values) {
            $name = strtolower((string) $name);
            $value = is_array($values) ? implode(', ', array_map('strval', $values)) : (string) $values;
            $out[$name] = $this->isSensitiveKey($name) || in_array($name, ['authorization', 'cookie', 'set-cookie', 'proxy-authorization', 'x-trk-secret'], true)
                ? self::REDACTED
                : $this->sanitizeString($value);
        }

        return $out;
    }

    private function sanitizeString(string $value): string
    {
        // Bearer / basic credentials embedded in strings.
        $value = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]{8,}/i', '$1 '.self::REDACTED, $value);

        // Payment card numbers (13–19 digits, optionally separated).
        $value = (string) preg_replace_callback('/\b\d(?:[ \-]?\d){12,18}\b/', function (array $m) {
            $digits = preg_replace('/\D/', '', $m[0]);

            return self::luhn($digits) ? self::REDACTED : $m[0];
        }, $value);

        return $value;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $alt = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return strlen($digits) >= 13 && $sum % 10 === 0;
    }

    private static function normalize(string $key): string
    {
        return str_replace(['-', '_', ' ', '.'], '', strtolower($key));
    }
}
