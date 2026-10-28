<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Symfony;

use Infaris\Monitoring\Doctrine\Middleware;
use Infaris\Monitoring\Monitor;
use Infaris\Monitoring\Monolog\GuardHandlerFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony and Shopware 6 integration. Register it in `config/bundles.php`:
 *
 *     Infaris\Monitoring\Symfony\InfarisMonitoringBundle::class => ['all' => true],
 *
 * It names every request after its route (`frontend.detail.page` in
 * Shopware), captures exceptions, adds the Doctrine middleware when
 * DoctrineBundle is installed and provides the Monolog handler service
 * `infaris_monitoring.monolog_handler`. Configuration comes from the
 * environment (INFARIS_MONITORING_API_KEY, ...; see Config::fromEnv()).
 */
final class InfarisMonitoringBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->setDefinition(
            RequestSubscriber::class,
            (new Definition(RequestSubscriber::class))->addTag('kernel.event_subscriber'),
        );

        if (interface_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
            $container->setDefinition(
                'infaris_monitoring.doctrine_middleware',
                (new Definition(Middleware::class))->addTag('doctrine.middleware'),
            );
        }

        if (interface_exists(\Monolog\Handler\HandlerInterface::class)) {
            $container->setDefinition(
                'infaris_monitoring.monolog_handler',
                (new Definition(\Monolog\Handler\HandlerInterface::class))
                    ->setFactory([GuardHandlerFactory::class, 'create'])
                    ->setArguments(['warning']),
            );
        }
    }

    public function boot(): void
    {
        // Without the front-controller hook the monitor starts here, which
        // still covers every request the kernel handles.
        Monitor::start();
    }
}
