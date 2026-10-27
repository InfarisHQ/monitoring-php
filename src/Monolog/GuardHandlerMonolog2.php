<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Monolog;

use Infaris\Monitoring\Monitor;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

/** Monolog 2 variant of GuardHandler. */
final class GuardHandlerMonolog2 extends AbstractProcessingHandler
{
    public function __construct($level = Logger::WARNING, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        Monitor::current()?->log(
            strtolower((string) $record['level_name']),
            (string) $record['message'],
            (array) ($record['context'] ?? []),
            (string) ($record['channel'] ?? 'app'),
        );
    }
}
