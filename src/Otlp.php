<?php

declare(strict_types=1);

namespace Infaris\Monitoring;

/** OTLP/HTTP JSON for the spans of one request. */
final class Otlp
{
    public const KIND_INTERNAL = 1;
    public const KIND_SERVER = 2;
    public const KIND_CLIENT = 3;

    /**
     * @param list<array<string, mixed>> $spans
     * @param array<string, scalar> $resource
     * @return array<string, mixed>
     */
    public static function tracesPayload(array $spans, array $resource): array
    {
        return [
            'resourceSpans' => [[
                'resource' => ['attributes' => self::attributes($resource)],
                'scopeSpans' => [[
                    'scope' => ['name' => 'infaris-monitoring-php', 'version' => Monitor::VERSION],
                    'spans' => array_map(static fn (array $span): array => [
                        'traceId' => $span['traceId'],
                        'spanId' => $span['spanId'],
                        'parentSpanId' => $span['parentSpanId'] ?? '',
                        'name' => $span['name'],
                        'kind' => $span['kind'],
                        'startTimeUnixNano' => self::nanos($span['start']),
                        'endTimeUnixNano' => self::nanos($span['end']),
                        'attributes' => self::attributes($span['attributes'] ?? []),
                        'status' => $span['error'] ?? false
                            ? ['code' => 2, 'message' => (string) ($span['statusMessage'] ?? '')]
                            : ['code' => 0],
                    ], $spans),
                ]],
            ]],
        ];
    }

    /** Unix seconds (float, microsecond precision) to a nanosecond string. */
    public static function nanos(float $seconds): string
    {
        $micro = (int) round($seconds * 1_000_000);

        return $micro . '000';
    }

    /**
     * @param array<string, mixed> $values
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    public static function attributes(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[] = ['key' => (string) $key, 'value' => match (true) {
                is_bool($value) => ['boolValue' => $value],
                is_int($value) => ['intValue' => (string) $value],
                is_float($value) => ['doubleValue' => $value],
                default => ['stringValue' => is_scalar($value) ? (string) $value : (string) json_encode($value)],
            }];
        }

        return $out;
    }
}
