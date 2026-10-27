<?php

declare(strict_types=1);

namespace Infaris\Monitoring;

/**
 * When the OpenTelemetry PHP extension and API are installed (for example
 * with open-telemetry/opentelemetry-auto-pdo for every PDO query in
 * Shopware), make the Guard request span their parent, so those spans land
 * in the request's trace instead of each starting their own.
 */
final class OpenTelemetryBridge
{
    private static ?object $scope = null;

    public static function activate(TraceContext $trace): void
    {
        if (self::$scope !== null
            || !class_exists(\OpenTelemetry\API\Trace\Span::class)
            || !class_exists(\OpenTelemetry\API\Trace\SpanContext::class)
            || !class_exists(\OpenTelemetry\Context\Context::class)) {
            return;
        }
        try {
            $current = \OpenTelemetry\Context\Context::getCurrent();
            if (\OpenTelemetry\API\Trace\Span::fromContext($current)->getContext()->isValid()) {
                return; // an OpenTelemetry server span is already active
            }
            $context = \OpenTelemetry\API\Trace\SpanContext::createFromRemoteParent(
                $trace->traceId,
                $trace->spanId,
                $trace->traceFlags,
            );
            self::$scope = \OpenTelemetry\API\Trace\Span::wrap($context)->storeInContext($current)->activate();
        } catch (\Throwable) {
            self::$scope = null;
        }
    }

    public static function deactivate(): void
    {
        try {
            self::$scope?->detach();
        } catch (\Throwable) {
        }
        self::$scope = null;
    }
}
