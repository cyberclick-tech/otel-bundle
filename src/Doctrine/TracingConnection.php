<?php
declare(strict_types=1);

namespace Cyberclick\OtelBundle\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;

final class TracingConnection extends AbstractConnectionMiddleware
{
    private const EXCLUDED_QUERIES = [
        '"START TRANSACTION"',
        '"COMMIT"',
        '"RELEASE SAVEPOINT"',
        '"ROLLBACK"',
        '"ROLLBACK TO SAVEPOINT"',
    ];

    public function __construct(
        Connection $connection,
        private readonly TracerInterface $tracer,
        private readonly string $instance,
        private readonly string $engine,
        private readonly bool $debugMode,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new TracingStatement(
            parent::prepare($sql),
            $this->tracer,
            $this->instance,
            $this->engine,
            $this->debugMode,
            $sql,
        );
    }

    public function query(string $sql): Result
    {
        $span = $this->startSpan($sql);

        try {
            return parent::query($sql);
        } finally {
            $span?->end();
        }
    }

    public function exec(string $sql): int|string
    {
        $span = $this->startSpan($sql);

        try {
            return parent::exec($sql);
        } finally {
            $span?->end();
        }
    }

    private function startSpan(string $sql): ?SpanInterface
    {
        if (!$this->debugMode && in_array($sql, self::EXCLUDED_QUERIES, true)) {
            return null;
        }

        try {
            $spanName = SqlParser::extractSpanName($sql);

            return $this->tracer->spanBuilder($spanName)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setAttribute('db.system', $this->engine)
                ->setAttribute('db.name', $this->instance)
                ->setAttribute('db.statement', $sql)
                ->setAttribute('db.operation', SqlParser::extractOperation($sql))
                ->startSpan();
        } catch (\Throwable) {
            return null;
        }
    }
}
