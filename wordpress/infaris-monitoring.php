<?php
/**
 * Plugin Name: Guard by Infaris monitoring
 * Description: Requests per page type, database queries, PHP errors and cache hits for Guard App Monitoring.
 * Version: 1.0.0
 *
 * Put this file in wp-content/mu-plugins/ and the SDK next to it in
 * wp-content/mu-plugins/infaris-monitoring/ (or install it with Composer).
 * Set PULSEGUARD_API_KEY in the environment or in wp-config.php:
 *
 *     define('PULSEGUARD_API_KEY', 'pgm_...');
 *     define('SAVEQUERIES', true); // database queries as spans
 */

declare(strict_types=1);

use Infaris\Monitoring\Config;
use Infaris\Monitoring\Monitor;

if (!class_exists(Monitor::class)) {
    $autoload = __DIR__ . '/infaris-monitoring/autoload.php';
    if (!is_file($autoload)) {
        return;
    }
    require_once $autoload;
}

$infarisOverrides = [];
foreach (['PULSEGUARD_API_KEY' => 'apiKey', 'PULSEGUARD_API_URL' => 'apiUrl', 'PULSEGUARD_ENVIRONMENT' => 'environment'] as $constant => $field) {
    if (defined($constant)) {
        $infarisOverrides[$field] = (string) constant($constant);
    }
}
$infarisMonitor = Monitor::start(Config::fromEnv($infarisOverrides));
$infarisMonitor->markFrameworkHandled();

// Route = page type, so all product pages share one row in Guard.
add_action('wp', static function () use ($infarisMonitor): void {
    $type = match (true) {
        is_front_page() => 'front-page',
        is_home() => 'blog',
        function_exists('is_product') && is_product() => 'single-product',
        function_exists('is_product_category') && is_product_category() => 'product-category',
        function_exists('is_cart') && is_cart() => 'cart',
        function_exists('is_checkout') && is_checkout() => 'checkout',
        is_singular() => 'single-' . (get_post_type() ?: 'post'),
        is_tax() || is_category() || is_tag() => 'taxonomy',
        is_archive() => 'archive',
        is_search() => 'search',
        is_404() => '404',
        default => 'other',
    };
    $infarisMonitor->setRoute('wp:' . $type);
});
add_action('rest_api_init', static function () use ($infarisMonitor): void {
    $infarisMonitor->setRoute('wp:rest');
});

// SAVEQUERIES keeps [sql, seconds, caller, start] per query (start since WP 5.x).
$infarisMonitor->beforeFinish(static function (Monitor $monitor): void {
    global $wpdb;
    if (!defined('SAVEQUERIES') || !SAVEQUERIES || !isset($wpdb->queries) || !is_array($wpdb->queries)) {
        return;
    }
    foreach ($wpdb->queries as $query) {
        $seconds = (float) ($query[1] ?? 0);
        $start = isset($query[3]) ? (float) $query[3] : microtime(true) - $seconds;
        $monitor->recordQuery('mysql', (string) ($query[0] ?? ''), $start, $start + $seconds);
    }
});
