<?php

/**
 * Autoloader for installs without Composer (copy this directory into the
 * project and require this file). With Composer, use vendor/autoload.php.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Infaris\\Monitoring\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
