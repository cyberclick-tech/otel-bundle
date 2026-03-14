<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;

final class TracingStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly TracerInterface $tracer,
        private readonly string $instance,
        private readonly string $engine,
        private readonly bool $debugMode,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $span = $this->startSpan();

        try {
            return parent::execute();
        } finally {
            $span?->end();
        }
    }

    private function startSpan(): ?SpanInterface
    {
        try {
            $spanName = SqlParser::extractSpanName($this->sql);

            return $this->tracer->spanBuilder($spanName)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setAttribute('db.system', $this->engine)
                ->setAttribute('db.name', $this->instance)
                ->setAttribute('db.statement', $this->sql)
                ->setAttribute('db.operation', SqlParser::extractOperation($this->sql))
                ->startSpan();
        } catch (\Throwable) {
            return null;
        }
    }
}
