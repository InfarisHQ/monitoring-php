<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Tests;

use Infaris\Monitoring\Config;
use Infaris\Monitoring\Monitor;
use PHPUnit\Framework\TestCase;

final class MonitorTest extends TestCase
{
    protected function setUp(): void
    {
        Monitor::reset();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/plafondlamp-shogun-55783?ref=mail';
        $_SERVER['HTTP_HOST'] = 'www.lampentotaal.nl';
        unset($_SERVER['HTTP_TRACEPARENT']);
    }

    protected function tearDown(): void
    {
        Monitor::reset();
    }

    public function testRequestLineAndQuerySpansShareOneTrace(): void
    {
        $transport = new RecordingTransport();
        $monitor = Monitor::start(new Config('pgm_test', httpCache: true), $transport, true);
        $monitor->markFrameworkHandled();
        $monitor->setRoute('frontend.detail.page');
        $monitor->recordQuery('mysql', "SELECT * FROM product WHERE id = 81 AND email = 'a@b.nl'", 1.0, 1.25);
        $monitor->finish();

        self::assertCount(2, $transport->posts);
        [$ingest, $traces] = $transport->posts;
        self::assertSame('/api/app-monitoring/ingest', $ingest['path']);
        $line = $ingest['body']['logs'][0];
        self::assertSame('/plafondlamp-shogun-55783', $line['path']);
        self::assertSame('frontend.detail.page', $line['route']);
        self::assertSame('MISS', $line['cacheStatus']);
        self::assertSame($monitor->trace()->traceId, $line['traceId']);

        self::assertSame('/api/app-monitoring/otlp/v1/traces', $traces['path']);
        $span = $traces['body']['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        self::assertSame($line['spanId'], $span['parentSpanId']);
        self::assertSame($line['traceId'], $span['traceId']);
        self::assertSame(3, $span['kind']);
        self::assertSame('1000000000', $span['startTimeUnixNano']);
        self::assertSame('1250000000', $span['endTimeUnixNano']);
        $attributes = array_column($span['attributes'], 'value', 'key');
        self::assertSame(['stringValue' => 'SELECT * FROM product WHERE id = ? AND email = ?'], $attributes['db.statement']);
    }

    public function testHttpCacheHitWhenTheKernelNeverRan(): void
    {
        $transport = new RecordingTransport();
        $monitor = Monitor::start(new Config('pgm_test', httpCache: true), $transport, true);
        $monitor->finish();

        self::assertSame('HIT', $transport->posts[0]['body']['logs'][0]['cacheStatus']);
        self::assertCount(1, $transport->posts, 'no spans, no OTLP call');
    }

    public function testContinuesAnIncomingTrace(): void
    {
        $_SERVER['HTTP_TRACEPARENT'] = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';
        $monitor = Monitor::start(new Config('pgm_test'), new RecordingTransport(), true);

        self::assertSame('0af7651916cd43dd8448eb211c80319c', $monitor->trace()->traceId);
        self::assertSame('b7ad6b7169203331', $monitor->trace()->parentSpanId);
        self::assertStringStartsWith(
            'traceparent;desc="00-0af7651916cd43dd8448eb211c80319c-',
            $monitor->trace()->serverTiming(),
        );
    }

    public function testLogsAtOrAboveTheLevelWithException(): void
    {
        $transport = new RecordingTransport();
        $monitor = Monitor::start(new Config('pgm_test'), $transport, false);
        $monitor->log('info', 'ignored');
        $monitor->log('error', 'Order export failed', ['exception' => new \RuntimeException('timeout'), 'order' => 42]);
        $monitor->finish();

        $lines = $transport->posts[0]['body']['logs'];
        self::assertCount(1, $lines);
        self::assertSame('APP_ERROR', $lines[0]['method']);
        self::assertSame('error', $lines[0]['level']);
        self::assertStringContainsString('RuntimeException: timeout', $lines[0]['errorStack']);
        self::assertSame(['order' => 42], $lines[0]['properties']);
    }

    public function testDisabledWithoutApiKey(): void
    {
        $transport = new RecordingTransport();
        $monitor = Monitor::start(new Config(''), $transport, true);
        $monitor->recordQuery('mysql', 'SELECT 1', 1.0, 1.1);
        $monitor->finish();

        self::assertSame([], $transport->posts);
    }

    public function testUnsampledTraceSendsNoSpans(): void
    {
        $_SERVER['HTTP_TRACEPARENT'] = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-00';
        $transport = new RecordingTransport();
        $monitor = Monitor::start(new Config('pgm_test'), $transport, true);
        $monitor->recordQuery('mysql', 'SELECT 1', 1.0, 1.1);
        $monitor->finish();

        self::assertCount(1, $transport->posts);
        self::assertSame(0, $transport->posts[0]['body']['logs'][0]['traceFlags']);
    }
}
