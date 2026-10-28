<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Tests;

use Infaris\Monitoring\CacheStatus;
use Infaris\Monitoring\Config;
use Infaris\Monitoring\Sql;
use Infaris\Monitoring\TraceContext;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testSqlObfuscation(): void
    {
        self::assertSame('SELECT * FROM t WHERE n = ? AND id IN (?)', Sql::obfuscate("SELECT * FROM t WHERE n = 'O''Brien' AND id IN (1, 2, 3)"));
        self::assertSame('INSERT INTO t (a) VALUES (?)', Sql::obfuscate('INSERT INTO t (a) VALUES (1), (2)'));
        self::assertSame('SELECT "col" FROM t WHERE a = :id', Sql::obfuscate('SELECT "col" FROM t WHERE a = :id'));
        self::assertSame('SELECT ? FROM p WHERE id = ?', Sql::obfuscate('/* x */ SELECT 1 FROM p WHERE id = 0x0190c6 -- tail'));
    }

    public function testDriverNames(): void
    {
        self::assertSame('mysql', Sql::systemFromDriver('pdo_mysql'));
        self::assertSame('postgresql', Sql::systemFromDriver('pdo_pgsql'));
    }

    public function testCacheHeaders(): void
    {
        self::assertSame('HIT', CacheStatus::fromHeaders(['X-Symfony-Cache: GET /lamp: fresh']));
        self::assertSame('MISS', CacheStatus::fromHeaders(['X-Symfony-Cache: GET /lamp: miss, store']));
        self::assertSame('REVALIDATED', CacheStatus::fromHeaders(['X-Symfony-Cache: GET /lamp: stale, valid, store']));
        self::assertSame('HIT', CacheStatus::fromHeaders(['Content-Type: text/html', 'X-Cache: HIT from varnish']));
        self::assertNull(CacheStatus::fromHeaders(['Content-Type: text/html']));
    }

    public function testTraceparentParsing(): void
    {
        self::assertNull(TraceContext::parse('00-00000000000000000000000000000000-b7ad6b7169203331-01'));
        self::assertNull(TraceContext::parse('garbage'));
        self::assertSame(1, TraceContext::parse('00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01')['traceFlags']);
    }

    public function testConfigPrefersInfarisNamesOverLegacy(): void
    {
        putenv('PULSEGUARD_API_KEY=pgm_old');
        try {
            self::assertSame('pgm_old', Config::fromEnv()->apiKey);
            putenv('INFARIS_MONITORING_API_KEY=pgm_new');
            self::assertSame('pgm_new', Config::fromEnv()->apiKey);
        } finally {
            putenv('PULSEGUARD_API_KEY');
            putenv('INFARIS_MONITORING_API_KEY');
        }
    }
}
