<?php

declare(strict_types=1);

namespace Infaris\Monitoring;

/**
 * One monitor per PHP request (or CLI run). Start it as early as possible -
 * the top of the front controller or `auto_prepend_file` - so the duration
 * includes bootstrapping and a response served from an HttpCache in front of
 * the framework is still seen. Framework integrations enrich it with the
 * route, queries and errors; everything is delivered after the response, at
 * shutdown.
 *
 * Delivery: the request line goes to the ingest API (Guard derives the root
 * span from it), the child spans (queries, outgoing calls) go to the OTLP
 * endpoint with that span as their parent.
 */
final class Monitor
{
    public const VERSION = '1.0.0';

    private const LEVELS = ['trace' => 0, 'debug' => 1, 'info' => 2, 'notice' => 2, 'warning' => 3, 'warn' => 3, 'error' => 4, 'critical' => 5, 'alert' => 5, 'emergency' => 5, 'fatal' => 5];
    private const MAX_LOG_LINES = 100;

    private static ?self $instance = null;

    private readonly TraceContext $trace;
    private readonly float $startedAt;
    private ?string $route = null;
    private ?string $cacheStatus = null;
    private bool $frameworkHandled = false;
    private bool $finished = false;
    /** @var list<array<string, mixed>> */
    private array $spans = [];
    private int $droppedSpans = 0;
    /** @var list<array<string, mixed>> */
    private array $logLines = [];
    /** @var list<callable(self): void> */
    private array $beforeFinish = [];

    private function __construct(
        private readonly Config $config,
        private readonly Transport $transport,
        private readonly bool $isWebRequest,
    ) {
        $this->startedAt = isset($_SERVER['REQUEST_TIME_FLOAT']) && $isWebRequest
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);
        $this->trace = TraceContext::forRequest(
            $isWebRequest ? self::server('HTTP_TRACEPARENT') : null,
            $isWebRequest ? self::server('HTTP_TRACESTATE') : null,
            $config->traceSampleRate,
        );
    }

    /**
     * Start (once) and return the monitor. Without an API key it returns a
     * monitor that records nothing, so integrations can call it freely.
     */
    public static function start(?Config $config = null, ?Transport $transport = null, ?bool $web = null): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $config ??= Config::fromEnv();
        $web ??= PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg';
        $monitor = new self($config, $transport ?? new Transport($config), $web);
        self::$instance = $monitor;
        if (!$config->enabled()) {
            return $monitor;
        }

        if ($web && !headers_sent()) {
            header('Server-Timing: ' . $monitor->trace->serverTiming(), false);
        }
        OpenTelemetryBridge::activate($monitor->trace);
        register_shutdown_function([$monitor, 'finish']);
        $previous = set_exception_handler(null);
        set_exception_handler(static function (\Throwable $e) use ($monitor, $previous): void {
            $monitor->captureException($e, 'fatal');
            if ($previous !== null) {
                $previous($e);
                return;
            }
            throw $e;
        });

        return $monitor;
    }

    public static function current(): ?self
    {
        return self::$instance;
    }

    /** For tests and long-running workers: forget the current request's monitor. */
    public static function reset(): void
    {
        OpenTelemetryBridge::deactivate();
        self::$instance = null;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function trace(): TraceContext
    {
        return $this->trace;
    }

    /** Route template (`/products/{id}`) or route name (`frontend.detail.page`). */
    public function setRoute(?string $route): void
    {
        if ($route !== null && $route !== '') {
            $this->route = substr($route, 0, 256);
        }
    }

    public function setCacheStatus(?string $status): void
    {
        $this->cacheStatus = $status !== null ? strtoupper(substr($status, 0, 32)) : null;
    }

    /**
     * Run just before delivery, for integrations that collect at the end of
     * the request (WordPress' SAVEQUERIES log, for example).
     *
     * @param callable(self): void $callback
     */
    public function beforeFinish(callable $callback): void
    {
        $this->beforeFinish[] = $callback;
    }

    /** The framework kernel handled this request (it was not a cache hit in front of it). */
    public function markFrameworkHandled(): void
    {
        $this->frameworkHandled = true;
    }

    /**
     * Record a span under the request span. Times are Unix seconds with
     * microseconds (microtime(true)).
     *
     * @param array<string, scalar|null> $attributes
     */
    public function recordSpan(
        string $name,
        int $kind,
        float $start,
        float $end,
        array $attributes = [],
        bool $error = false,
        ?string $statusMessage = null,
    ): void {
        if (!$this->config->enabled() || !$this->trace->sampled()) {
            return;
        }
        if (count($this->spans) >= $this->config->maxSpans) {
            $this->droppedSpans++;
            return;
        }
        $this->spans[] = [
            'traceId' => $this->trace->traceId,
            'spanId' => TraceContext::randomHex(8),
            'parentSpanId' => $this->trace->spanId,
            'name' => substr($name, 0, 512),
            'kind' => $kind,
            'start' => $start,
            'end' => max($start, $end),
            'attributes' => $attributes,
            'error' => $error,
            'statusMessage' => $statusMessage,
        ];
    }

    /** A database call; the statement is obfuscated before it is stored. */
    public function recordQuery(string $system, string $sql, float $start, float $end, bool $error = false, ?string $errorMessage = null): void
    {
        if (!$this->config->captureQueries) {
            return;
        }
        $statement = Sql::obfuscate($sql);
        $operation = preg_match('/^\s*\(?\s*([a-z]+)/i', $statement, $m) ? strtoupper($m[1]) : 'QUERY';
        $this->recordSpan(
            $operation . ' ' . $system,
            Otlp::KIND_CLIENT,
            $start,
            $end,
            ['db.system' => $system, 'db.statement' => $statement, 'db.operation' => $operation],
            $error,
            $errorMessage,
        );
    }

    /** An outgoing HTTP call. */
    public function recordHttpCall(string $method, string $url, int $status, float $start, float $end): void
    {
        $host = parse_url($url, PHP_URL_HOST) ?: null;
        $this->recordSpan(
            strtoupper($method) . ' ' . ($host ?? $url),
            Otlp::KIND_CLIENT,
            $start,
            $end,
            [
                'http.request.method' => strtoupper($method),
                'url.full' => strtok($url, '?') ?: $url,
                'server.address' => $host,
                'http.response.status_code' => $status,
            ],
            $status === 0 || $status >= 500,
        );
    }

    public function captureException(\Throwable $e, string $level = 'error'): void
    {
        $this->addLogLine($level, $e->getMessage() !== '' ? $e->getMessage() : $e::class, [
            'errorStack' => self::stackOf($e),
            'source' => $e->getFile() . ':' . $e->getLine(),
            'logger' => $e::class,
        ]);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = [], ?string $logger = null): void
    {
        $level = strtolower($level);
        if ((self::LEVELS[$level] ?? 2) < (self::LEVELS[$this->config->minLogLevel] ?? 3)) {
            return;
        }
        $exception = $context['exception'] ?? null;
        unset($context['exception']);
        $this->addLogLine($level, $message, [
            'errorStack' => $exception instanceof \Throwable ? self::stackOf($exception) : null,
            'logger' => $logger,
            'properties' => $context !== [] ? self::scalarContext($context) : null,
        ]);
    }

    /** Deliver everything. Called at shutdown; safe to call more than once. */
    public function finish(): void
    {
        if ($this->finished || !$this->config->enabled()) {
            return;
        }
        $this->finished = true;
        OpenTelemetryBridge::deactivate();
        foreach ($this->beforeFinish as $callback) {
            try {
                $callback($this);
            } catch (\Throwable) {
            }
        }

        $fatal = error_get_last();
        if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $this->addLogLine('fatal', $fatal['message'], ['source' => $fatal['file'] . ':' . $fatal['line']]);
        }

        if ($this->config->finishRequestFirst && $this->isWebRequest && function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $logs = $this->logLines;
        if ($this->isWebRequest) {
            array_unshift($logs, $this->requestLine(microtime(true)));
        }
        if ($logs !== []) {
            $this->transport->post('/api/app-monitoring/ingest', ['logs' => $logs]);
        }
        if ($this->spans !== [] && $this->isWebRequest) {
            $this->transport->post(
                '/api/app-monitoring/otlp/v1/traces',
                Otlp::tracesPayload($this->spans, $this->resource()),
            );
        }
    }

    /** @return array<string, mixed> */
    public function requestLine(float $endedAt): array
    {
        $status = http_response_code();
        $headers = headers_list();
        $cache = $this->cacheStatus ?? CacheStatus::fromHeaders($headers);
        if ($cache === null && $this->config->httpCache) {
            $cache = $this->frameworkHandled ? 'MISS' : 'HIT';
        }
        $method = strtoupper(self::server('REQUEST_METHOD') ?? 'GET');
        $path = parse_url(self::server('REQUEST_URI') ?? '/', PHP_URL_PATH) ?: '/';
        $host = self::server('HTTP_HOST') ?? self::server('SERVER_NAME') ?? 'localhost';
        $duration = (int) round(($endedAt - $this->startedAt) * 1000);

        return array_filter([
            'timestamp' => self::iso($this->startedAt),
            'method' => $method,
            'path' => $path,
            'route' => $this->route,
            'host' => explode(':', $host)[0],
            'statusCode' => is_int($status) && $status >= 100 ? $status : 200,
            'duration' => max(0, $duration),
            'executionDuration' => max(0, $duration),
            'memoryUsage' => memory_get_peak_usage(true),
            'userAgent' => self::server('HTTP_USER_AGENT'),
            'ip' => self::clientIp(),
            'requestId' => self::server('HTTP_X_REQUEST_ID'),
            'cacheStatus' => $cache,
            'environment' => $this->config->environment ?? 'production',
            'version' => $this->config->version,
            'commitSha' => $this->config->commitSha,
            'serviceName' => $this->serviceName(),
            'traceId' => $this->trace->traceId,
            'spanId' => $this->trace->spanId,
            'parentSpanId' => $this->trace->parentSpanId,
            'traceFlags' => $this->trace->traceFlags,
            'traceState' => $this->trace->traceState,
            'properties' => $this->droppedSpans > 0 ? ['guard.dropped_spans' => $this->droppedSpans] : null,
        ], static fn ($v) => $v !== null);
    }

    /** @return list<array<string, mixed>> */
    public function pendingSpans(): array
    {
        return $this->spans;
    }

    /** @return list<array<string, mixed>> */
    public function pendingLogLines(): array
    {
        return $this->logLines;
    }

    /** @param array<string, mixed> $extra */
    private function addLogLine(string $level, string $message, array $extra): void
    {
        if (!$this->config->enabled() || count($this->logLines) >= self::MAX_LOG_LINES) {
            return;
        }
        $level = match ($level) {
            'warning' => 'warn',
            'notice' => 'info',
            'critical', 'alert', 'emergency' => 'fatal',
            default => $level,
        };
        $this->logLines[] = array_filter([
            'timestamp' => self::iso(microtime(true)),
            'method' => 'APP_ERROR',
            'path' => $this->route ?? (parse_url(self::server('REQUEST_URI') ?? '', PHP_URL_PATH) ?: (PHP_SAPI === 'cli' ? 'cli' : '/')),
            'host' => explode(':', self::server('HTTP_HOST') ?? (gethostname() ?: 'localhost'))[0],
            'statusCode' => 0,
            'duration' => 0,
            'level' => $level,
            'errorMessage' => substr($message, 0, 4096),
            'environment' => $this->config->environment ?? 'production',
            'version' => $this->config->version,
            'commitSha' => $this->config->commitSha,
            'serviceName' => $this->serviceName(),
            'traceId' => $this->trace->traceId,
            'spanId' => $this->trace->spanId,
        ] + array_filter($extra, static fn ($v) => $v !== null), static fn ($v) => $v !== null);
    }

    /** @return array<string, scalar> */
    private function resource(): array
    {
        return array_filter([
            'service.name' => $this->serviceName(),
            'service.version' => $this->config->version,
            'deployment.environment' => $this->config->environment,
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.name' => 'infaris-monitoring-php',
            'telemetry.sdk.version' => self::VERSION,
        ], static fn ($v) => $v !== null);
    }

    private function serviceName(): string
    {
        return $this->config->serviceName
            ?? explode(':', self::server('HTTP_HOST') ?? (gethostname() ?: 'php'))[0];
    }

    private static function server(string $key): ?string
    {
        $value = $_SERVER[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function clientIp(): ?string
    {
        $forwarded = self::server('HTTP_CF_CONNECTING_IP') ?? self::server('HTTP_X_FORWARDED_FOR');
        if ($forwarded !== null) {
            return trim(explode(',', $forwarded)[0]);
        }

        return self::server('REMOTE_ADDR');
    }

    private static function iso(float $seconds): string
    {
        $date = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $seconds), new \DateTimeZone('UTC'));

        return $date !== false ? $date->format('Y-m-d\TH:i:s.v\Z') : gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function stackOf(\Throwable $e): string
    {
        $stack = $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
        $previous = $e->getPrevious();
        while ($previous !== null) {
            $stack .= "\nCaused by " . $previous::class . ': ' . $previous->getMessage() . "\n" . $previous->getTraceAsString();
            $previous = $previous->getPrevious();
        }

        return substr($stack, 0, 8192);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, scalar|null>
     */
    private static function scalarContext(array $context): array
    {
        $out = [];
        foreach (array_slice($context, 0, 30, true) as $key => $value) {
            $out[(string) $key] = match (true) {
                is_scalar($value), $value === null => $value,
                $value instanceof \Stringable => substr((string) $value, 0, 512),
                is_object($value) => $value::class,
                default => substr((string) json_encode($value), 0, 512),
            };
        }

        return $out;
    }
}
