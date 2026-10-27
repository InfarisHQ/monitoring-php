<?php

declare(strict_types=1);

namespace Infaris\Monitoring;

/** W3C trace context of the current request. */
final class TraceContext
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly int $traceFlags,
        public readonly ?string $traceState = null,
    ) {
    }

    /** Continue an incoming `traceparent`, or start a new trace sampled at `$sampleRate`. */
    public static function forRequest(?string $traceparent, ?string $traceState, float $sampleRate): self
    {
        $parent = self::parse($traceparent);
        if ($parent !== null) {
            return new self($parent['traceId'], self::randomHex(8), $parent['spanId'], $parent['traceFlags'], $traceState);
        }
        $sampled = $sampleRate >= 1.0 || ($sampleRate > 0 && mt_rand() / mt_getrandmax() < $sampleRate);

        return new self(self::randomHex(16), self::randomHex(8), null, $sampled ? 1 : 0);
    }

    /** @return array{traceId: string, spanId: string, traceFlags: int}|null */
    public static function parse(?string $header): ?array
    {
        if ($header === null) {
            return null;
        }
        if (!preg_match('/^\s*([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})\s*$/i', $header, $m)) {
            return null;
        }
        $traceId = strtolower($m[2]);
        $spanId = strtolower($m[3]);
        if (strtolower($m[1]) === 'ff' || trim($traceId, '0') === '' || trim($spanId, '0') === '') {
            return null;
        }

        return ['traceId' => $traceId, 'spanId' => $spanId, 'traceFlags' => hexdec($m[4]) & 0xff];
    }

    public static function randomHex(int $bytes): string
    {
        do {
            $hex = bin2hex(random_bytes($bytes));
        } while (trim($hex, '0') === '');

        return $hex;
    }

    public function sampled(): bool
    {
        return ($this->traceFlags & 1) === 1;
    }

    public function traceparent(?string $spanId = null): string
    {
        return sprintf('00-%s-%s-%02x', $this->traceId, $spanId ?? $this->spanId, $this->traceFlags);
    }

    /**
     * `Server-Timing` entry that hands this trace to the browser, where Guard
     * Insights links the page view to it.
     */
    public function serverTiming(): string
    {
        return sprintf('traceparent;desc="%s"', $this->traceparent());
    }
}
