<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Doctrine;

/** Loaded only with doctrine/dbal 3. */
final class ConnectionDbal3 extends Connection
{
    public function exec(string $sql): int
    {
        return $this->measure($sql, fn () => parent::exec($sql));
    }
}
