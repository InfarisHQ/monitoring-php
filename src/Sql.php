<?php

declare(strict_types=1);

namespace Infaris\Monitoring;

/**
 * Literals out of SQL before it leaves the server. Guard groups queries on the
 * same shape (apps/guard/api/src/modules/app-monitoring/db-query.ts) and does
 * this again on arrival; doing it here means an e-mail address in a WHERE
 * clause never goes over the wire.
 */
final class Sql
{
    private const MAX_LENGTH = 2048;

    public static function obfuscate(string $sql): string
    {
        $out = preg_replace(
            [
                '~/\*.*?\*/~s',
                '~--[^\n]*~',
                "~'(?:[^'\\\\]|\\\\.|'')*'~s",
                '~\b0x[0-9a-f]+\b~i',
                '~(?<![\w$.:])-?\d+(?:\.\d+)?(?:e[+-]?\d+)?\b~i',
                '~\s+~',
            ],
            [' ', ' ', '?', '?', '?', ' '],
            $sql,
        ) ?? $sql;
        // MySQL double-quoted strings; a plain "identifier" (Postgres) stays.
        $out = preg_replace_callback(
            '~"(?:[^"\\\\]|\\\\.|"")*"~s',
            static fn (array $m): string => preg_match('~^"[A-Za-z_][A-Za-z0-9_$]*"$~', $m[0]) === 1 ? $m[0] : '?',
            $out,
        ) ?? $out;
        $out = trim($out);
        $out = preg_replace(
            [
                '~\(\s*\?(?:\s*,\s*\?)*\s*\)~',
                '~\(\s*(?::\w+|\$\d+)(?:\s*,\s*(?::\w+|\$\d+))+\s*\)~',
                '~(VALUES\s*\(\?\))(?:\s*,\s*\(\?\))+~i',
            ],
            ['(?)', '(?)', '$1'],
            $out,
        ) ?? $out;

        return strlen($out) > self::MAX_LENGTH ? substr($out, 0, self::MAX_LENGTH - 3) . '...' : $out;
    }

    /** OpenTelemetry `db.system` for a PDO/DBAL driver name. */
    public static function systemFromDriver(?string $driver): string
    {
        $driver = strtolower((string) $driver);

        return match (true) {
            str_contains($driver, 'mysql') => 'mysql',
            str_contains($driver, 'pgsql'), str_contains($driver, 'postgres') => 'postgresql',
            str_contains($driver, 'sqlsrv'), str_contains($driver, 'mssql') => 'mssql',
            str_contains($driver, 'sqlite') => 'sqlite',
            str_contains($driver, 'oci'), str_contains($driver, 'oracle') => 'oracle',
            default => 'other_sql',
        };
    }
}
