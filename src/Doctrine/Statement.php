<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;

final class Statement extends AbstractStatementMiddleware
{
    public function __construct(DriverStatement $statement, private readonly string $sql, private readonly string $system)
    {
        parent::__construct($statement);
    }

    /**
     * DBAL 3 passes optional parameters here, DBAL 4 never does; the optional
     * argument keeps the signature compatible with both.
     */
    public function execute($params = null): Result
    {
        return Connection::timed(
            $this->system,
            $this->sql,
            fn () => $params === null ? parent::execute() : parent::execute($params),
        );
    }
}
