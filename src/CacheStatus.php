<?php

declare(strict_types=1);

namespace Infaris\Monitoring;

/** HIT/MISS/STALE/BYPASS of a response, from the cache headers it carries. */
final class CacheStatus
{
    /** @param list<string> $headers lines as returned by headers_list() */
    public static function fromHeaders(array $headers): ?string
    {
        foreach ($headers as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            $name = strtolower($name);
            $value = strtolower($value);
            if ($name === 'x-symfony-cache') {
                // "GET /lamp: fresh", "GET /lamp: stale, valid, store", "GET /x: miss, store"
                return match (true) {
                    str_contains($value, 'fresh') => 'HIT',
                    str_contains($value, 'stale') && str_contains($value, 'valid') => 'REVALIDATED',
                    str_contains($value, 'stale') => 'STALE',
                    str_contains($value, 'pass'), str_contains($value, 'invalid') => 'BYPASS',
                    str_contains($value, 'miss') => 'MISS',
                    default => null,
                };
            }
            if (in_array($name, ['x-cache', 'x-cache-status', 'x-proxy-cache', 'x-litespeed-cache'], true)) {
                return match (true) {
                    str_starts_with($value, 'hit') => 'HIT',
                    str_starts_with($value, 'miss') => 'MISS',
                    str_starts_with($value, 'stale') => 'STALE',
                    str_starts_with($value, 'bypass'), str_starts_with($value, 'pass') => 'BYPASS',
                    str_starts_with($value, 'expired') => 'EXPIRED',
                    default => null,
                };
            }
        }

        return null;
    }
}
