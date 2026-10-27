<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Doctrine;

/** Loaded only with doctrine/dbal 4. */
final class ConnectionDbal4 extends Connection
{
    public function exec(string $sql): int|string
    {
        return $this->measure($sql, fn () => parent::exec($sql));
    }
}
