<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Monolog;

use Infaris\Monitoring\Monitor;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog 3 handler: log records at or above the monitor's level become
 * application log lines in Guard, linked to the request trace. For Monolog 2
 * use `GuardHandlerMonolog2`; `GuardHandlerFactory::create()` picks the right
 * one.
 */
final class GuardHandler extends AbstractProcessingHandler
{
    public function __construct(int|string|Level $level = Level::Warning, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        Monitor::current()?->log(
            strtolower($record->level->getName()),
            $record->message,
            $record->context,
            $record->channel,
        );
    }
}
