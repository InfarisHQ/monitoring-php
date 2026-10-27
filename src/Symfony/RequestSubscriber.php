<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Symfony;

use Infaris\Monitoring\Monitor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 4096],
            KernelEvents::CONTROLLER => ['onController', 0],
            KernelEvents::EXCEPTION => ['onException', 0],
            KernelEvents::RESPONSE => ['onResponse', -4096],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            Monitor::start()->markFrameworkHandled();
        }
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $route = $event->getRequest()->attributes->get('_route');
        Monitor::current()?->setRoute(is_string($route) ? $route : null);
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        // 4xx are answers, not failures; they show as status codes already.
        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            && $exception->getStatusCode() < 500) {
            return;
        }
        Monitor::current()?->captureException($exception);
    }

    public function onResponse(ResponseEvent $event): void
    {
        $monitor = Monitor::current();
        if ($monitor === null || !$event->isMainRequest() || !$monitor->config()->enabled()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $timing = $monitor->trace()->serverTiming();
        $existing = $headers->get('Server-Timing');
        if ($existing === null || !str_contains($existing, 'traceparent')) {
            $headers->set('Server-Timing', $existing ? $existing . ', ' . $timing : $timing);
        }
    }
}
