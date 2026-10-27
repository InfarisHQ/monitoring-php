<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Monolog;

use Monolog\Handler\HandlerInterface;

final class GuardHandlerFactory
{
    /** The handler for the installed Monolog major version. */
    public static function create(string $level = 'warning'): HandlerInterface
    {
        return class_exists(\Monolog\LogRecord::class)
            ? new GuardHandler($level)
            : new GuardHandlerMonolog2($level);
    }
}
