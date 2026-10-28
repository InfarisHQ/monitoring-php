# Guard App Monitoring for PHP

`infaris/monitoring` sends requests, routes, cache hits, database queries,
errors and logs from a PHP application to Guard by Infaris. It feeds the
Performance tab: latency and Apdex per route, the cache hit/miss split, the
slowest query shapes, N+1 patterns and the time per layer.

Pure PHP 8.1+, no extension needed. Delivery happens at shutdown, after the
response, with a 2 second timeout; telemetry never throws into the app.

## Install

```bash
composer require infaris/monitoring
```

```bash
INFARIS_MONITORING_API_KEY=pgm_...            # the App Monitoring key, from a secret store
INFARIS_MONITORING_ENVIRONMENT=production     # optional, falls back to APP_ENV
INFARIS_MONITORING_HTTP_CACHE=1               # Shopware/Symfony HttpCache: count hits and misses
INFARIS_MONITORING_FINISH_REQUEST_FIRST=1     # PHP-FPM: answer the visitor before delivering
```

All settings: `src/Config.php` (`Config::fromEnv()`). The older
`PULSEGUARD_*` names are still read as a fallback.

### Shopware 6 and Symfony

`config/bundles.php`:

```php
Infaris\Monitoring\Symfony\InfarisMonitoringBundle::class => ['all' => true],
```

The bundle names every request after its route (`frontend.detail.page`,
`frontend.navigation.page`), captures 5xx exceptions, adds the
`Server-Timing` trace header and, with DoctrineBundle, registers the DBAL
middleware so every query becomes a span. Monolog: point a handler at the
service `infaris_monitoring.monolog_handler`.

Requests answered by an HttpCache in front of the kernel never reach the
bundle. Start the monitor before the kernel to count them as cache hits:
`auto_prepend_file` (below) or `Monitor::start()` at the top of
`public/index.php`.

Shopware creates its database connection without DoctrineBundle. For its
queries, install the OpenTelemetry extension with PDO instrumentation; the
SDK makes its request span their parent, so they land in the same trace:

```bash
pecl install opentelemetry
composer require open-telemetry/sdk open-telemetry/exporter-otlp \
  open-telemetry/opentelemetry-auto-pdo php-http/guzzle7-adapter
```

```bash
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_EXPORTER_OTLP_ENDPOINT=https://api.ipulse.one/api/app-monitoring/otlp
OTEL_EXPORTER_OTLP_HEADERS=x-api-key=pgm_...
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_METRICS_EXPORTER=none
INFARIS_MONITORING_CAPTURE_QUERIES=0   # queries now come from PDO
```

### Laravel

Package discovery registers `Infaris\Monitoring\Laravel\GuardServiceProvider`:
route templates, every query from the query events and logged warnings and
errors (reported exceptions included). No middleware.

### WordPress

Copy `wordpress/infaris-monitoring.php` to `wp-content/mu-plugins/` (with the
SDK in `wp-content/mu-plugins/infaris-monitoring/` or via Composer). Routes are
page types (`wp:single-product`, `wp:checkout`, ...). In `wp-config.php`:

```php
define('INFARIS_MONITORING_API_KEY', getenv('INFARIS_MONITORING_API_KEY'));
define('SAVEQUERIES', true); // database queries as spans
```

### Any PHP application

```ini
; php.ini, .user.ini or the PHP-FPM pool
auto_prepend_file = /var/www/vendor/infaris/monitoring/prepend.php
```

Or in code, as early as possible:

```php
$monitor = Infaris\Monitoring\Monitor::start();
$monitor->setRoute('/products/{id}');
$monitor->recordQuery('mysql', $sql, $start, microtime(true));
$monitor->recordHttpCall('GET', $url, $status, $start, microtime(true));
$monitor->captureException($e);
$monitor->log('error', 'Export failed', ['order' => 42]);
```

Doctrine DBAL 3.2+ and 4 outside Symfony:
`$config->setMiddlewares([new Infaris\Monitoring\Doctrine\Middleware()])`.

## What is sent

- One request line per web request to `POST /api/app-monitoring/ingest`:
  method, path (no query string), route, status, duration, cache status,
  peak memory, client IP and user agent (Guard redacts them per its scrub
  rules), release and the W3C trace ids. Guard derives the request span from it.
- Child spans (queries, outgoing calls) as OTLP JSON to
  `POST /api/app-monitoring/otlp/v1/traces`, only for sampled traces
  (`INFARIS_MONITORING_TRACE_SAMPLE_RATE`). SQL literals are replaced by `?` before
  sending; Guard does it again on arrival.
- Application log lines for exceptions, fatal errors and log records at or
  above `INFARIS_MONITORING_LOG_LEVEL` (default `warning`).

## Development

```bash
composer install
vendor/bin/phpunit
```
