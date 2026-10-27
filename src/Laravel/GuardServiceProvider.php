<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Laravel;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider;
use Infaris\Monitoring\Monitor;
use Infaris\Monitoring\Sql;

/**
 * Laravel integration, registered through package discovery: route names per
 * request, every query from the query log events and warnings/errors from
 * the logger (reported exceptions included).
 */
final class GuardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $monitor = Monitor::start();
        if (!$monitor->config()->enabled()) {
            return;
        }
        $monitor->markFrameworkHandled();
        $events = $this->app['events'];

        $events->listen(RouteMatched::class, static function (RouteMatched $event) use ($monitor): void {
            $monitor->setRoute('/' . ltrim($event->route->uri(), '/'));
        });

        $events->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($monitor): void {
            // `time` is the duration in ms; the event fires right after the query.
            $end = microtime(true);
            $monitor->recordQuery(
                Sql::systemFromDriver($event->connection->getDriverName()),
                $event->sql,
                $end - $event->time / 1000,
                $end,
            );
        });

        $events->listen(MessageLogged::class, static function (MessageLogged $event) use ($monitor): void {
            $monitor->log($event->level, (string) $event->message, $event->context, 'laravel');
        });
    }
}
