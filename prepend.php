<?php

/**
 * Start Guard monitoring before the application runs, without touching its
 * code. In php.ini, .user.ini or the PHP-FPM pool:
 *
 *     auto_prepend_file = /path/to/vendor/infaris/monitoring/prepend.php
 *
 * Starting this early also counts responses that an HttpCache in front of
 * the framework serves (INFARIS_MONITORING_HTTP_CACHE=1 marks those as cache hits).
 */

declare(strict_types=1);

if (!class_exists(\Infaris\Monitoring\Monitor::class, false)) {
    require_once __DIR__ . '/autoload.php';
}

\Infaris\Monitoring\Monitor::start();
