<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle\Messenger;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class TracingMiddleware implements MiddlewareInterface
{
    private const MAX_POOL_COUNT = 20;

    private TracerInterface $tracer;
    private NameExtractorInterface $nameExtractor;
    private int $poolCount;

    public function __construct(
        TracerInterface $tracer,
        NameExtractorInterface $nameExtractor,
    ) {
        $this->tracer = $tracer;
        $this->nameExtractor = $nameExtractor;
        $this->poolCount = 0;
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $name = $this->nameExtractor->execute(
            $envelope->getMessage()
        );

        if ($this->poolCount > self::MAX_POOL_COUNT) {
            return $stack->next()->handle($envelope, $stack);
        }

        $span = $this->tracer->spanBuilder($name)
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.system', 'rabbitmq')
            ->setAttribute('messaging.operation', 'process')
            ->startSpan();

        $scope = $span->activate();
        $this->poolCount++;

        try {
            $envelope = $stack->next()->handle($envelope, $stack);

            $span->setStatus(StatusCode::STATUS_OK);
        } catch (\Throwable $throwable) {
            $span->recordException($throwable);
            $span->setStatus(StatusCode::STATUS_ERROR, $throwable->getMessage());

            $span->end();
            $scope->detach();

            throw $throwable;
        }

        $span->end();
        $scope->detach();

        return $envelope;
    }
}
