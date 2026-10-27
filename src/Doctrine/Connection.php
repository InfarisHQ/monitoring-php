<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Infaris\Monitoring\Monitor;

/** Shared part of the DBAL 3 and 4 connection wrappers. */
abstract class Connection extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $connection, protected readonly string $system)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): DriverStatement
    {
        return new Statement(parent::prepare($sql), $sql, $this->system);
    }

    public function query(string $sql): Result
    {
        return $this->measure($sql, fn () => parent::query($sql));
    }

    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    protected function measure(string $sql, callable $run): mixed
    {
        return self::timed($this->system, $sql, $run);
    }

    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    public static function timed(string $system, string $sql, callable $run): mixed
    {
        $monitor = Monitor::current();
        if ($monitor === null) {
            return $run();
        }
        $start = microtime(true);
        try {
            $result = $run();
        } catch (\Throwable $e) {
            $monitor->recordQuery($system, $sql, $start, microtime(true), true, $e->getMessage());
            throw $e;
        }
        $monitor->recordQuery($system, $sql, $start, microtime(true));

        return $result;
    }
}
