<?php

declare(strict_types=1);

namespace Infaris\Monitoring;

/**
 * Settings for the monitor. `fromEnv()` reads the same variable names as the
 * Node and .NET SDKs, so one secret works for every service of an app.
 */
final class Config
{
    public function __construct(
        public readonly string $apiKey,
        public readonly string $apiUrl = 'https://api.ipulse.one',
        public readonly ?string $environment = null,
        public readonly ?string $serviceName = null,
        /** Share of requests (0..1) whose spans are sent; request lines are always sent. */
        public readonly float $traceSampleRate = 1.0,
        /** Record database queries as spans (literals are replaced by `?` before sending). */
        public readonly bool $captureQueries = true,
        /** Lowest log level forwarded as an application log line. */
        public readonly string $minLogLevel = 'warning',
        /**
         * A Symfony/Shopware HttpCache sits in front of the kernel: a request
         * the kernel never handled was served from the cache (a hit).
         */
        public readonly bool $httpCache = false,
        /** Send the response to the client before delivering telemetry (PHP-FPM). */
        public readonly bool $finishRequestFirst = false,
        public readonly ?string $version = null,
        public readonly ?string $commitSha = null,
        /** Seconds per delivery request. */
        public readonly float $timeout = 2.0,
        /** Spans kept per request; the rest is counted in `guard.dropped_spans`. */
        public readonly int $maxSpans = 2000,
    ) {
    }

    public static function fromEnv(array $overrides = []): self
    {
        $env = static function (string ...$names): ?string {
            foreach ($names as $name) {
                $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
            return null;
        };
        $bool = static fn (?string $v, bool $default): bool => $v === null
            ? $default
            : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);

        $values = [
            'apiKey' => $env('PULSEGUARD_API_KEY', 'GUARD_API_KEY') ?? '',
            'apiUrl' => rtrim($env('PULSEGUARD_API_URL') ?? 'https://api.ipulse.one', '/'),
            'environment' => $env('PULSEGUARD_ENVIRONMENT', 'APP_ENV'),
            'serviceName' => $env('PULSEGUARD_SERVICE_NAME', 'OTEL_SERVICE_NAME'),
            'traceSampleRate' => (float) ($env('PULSEGUARD_TRACE_SAMPLE_RATE') ?? 1.0),
            'captureQueries' => $bool($env('PULSEGUARD_CAPTURE_QUERIES'), true),
            'minLogLevel' => strtolower($env('PULSEGUARD_LOG_LEVEL') ?? 'warning'),
            'httpCache' => $bool($env('PULSEGUARD_HTTP_CACHE', 'SHOPWARE_HTTP_CACHE_ENABLED'), false),
            'finishRequestFirst' => $bool($env('PULSEGUARD_FINISH_REQUEST_FIRST'), false),
            'version' => $env('PULSEGUARD_VERSION', 'APP_VERSION'),
            'commitSha' => $env('PULSEGUARD_COMMIT_SHA', 'GIT_COMMIT', 'COMMIT_SHA'),
        ];

        return new self(...array_merge($values, $overrides));
    }

    public function enabled(): bool
    {
        return $this->apiKey !== '';
    }
}
