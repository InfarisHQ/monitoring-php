<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Infaris\Monitoring\Sql;

final class Driver extends AbstractDriverMiddleware
{
    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        $system = Sql::systemFromDriver($params['driver'] ?? $params['driverClass'] ?? null);
        $connection = parent::connect($params);

        // DBAL 4 widened exec()'s return type to int|string; each variant
        // declares its own, and only the one matching the installed DBAL is
        // ever loaded.
        return interface_exists(\Doctrine\DBAL\ServerVersionProvider::class)
            ? new ConnectionDbal4($connection, $system)
            : new ConnectionDbal3($connection, $system);
    }
}
