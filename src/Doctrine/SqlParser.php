<?php
declare(strict_types=1);

namespace Cyberclick\OtelBundle\Doctrine;

final class SqlParser
{
    public static function extractSpanName(string $sql): string
    {
        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\s+(?:INTO\s+)?(?:FROM\s+)?[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            return strtoupper($matches[1]) . ' ' . $matches[2];
        }

        return 'DB Query';
    }

    public static function extractOperation(string $sql): string
    {
        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)/i', $sql, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'QUERY';
    }
}
