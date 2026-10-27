<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

/**
 * Doctrine DBAL (3.2+ and 4) middleware: every query becomes a span of the
 * current request. Symfony with DoctrineBundle picks it up through the
 * `doctrine.middleware` tag (the bundle registers it); elsewhere add it to
 * the DBAL Configuration: `$config->setMiddlewares([new Middleware()])`.
 */
final class Middleware implements MiddlewareInterface
{
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new Driver($driver);
    }
}
