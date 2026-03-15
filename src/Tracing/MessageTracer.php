<?php
declare(strict_types=1);

namespace Cyberclick\OtelBundle\Tracing;

use Cyberclick\OtelBundle\SpanAttributeExtractorInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use Throwable;

final class MessageTracer implements MessageTracerInterface
{
    private const MAX_SPANS = 25;

    private TracerInterface $tracer;
    private TracerProviderInterface $tracerProvider;
    private SpanAttributeExtractorInterface $attributeExtractor;
    private ?SpanInterface $rootSpan;
    private ?ScopeInterface $rootScope;
    /** @var array<string, SpanInterface> */
    private array $spans;
    private int $spanCounter;

    public function __construct(
        TracerInterface $tracer,
        TracerProviderInterface $tracerProvider,
        SpanAttributeExtractorInterface $attributeExtractor,
    ) {
        $this->tracer = $tracer;
        $this->tracerProvider = $tracerProvider;
        $this->attributeExtractor = $attributeExtractor;
        $this->rootSpan = null;
        $this->rootScope = null;
        $this->spans = [];
        $this->spanCounter = 0;
    }

    public function start(string $queue, ?string $body = null): void
    {
        $attributes = $this->attributeExtractor->fromMessageBody($body);

        $spanBuilder = $this->tracer->spanBuilder($queue)
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('messaging.system', 'rabbitmq')
            ->setAttribute('messaging.destination', $queue)
            ->setAttribute('messaging.operation', 'process');

        foreach ($attributes as $key => $value) {
            $spanBuilder->setAttribute($key, $value);
        }

        $this->rootSpan = $spanBuilder->startSpan();
        $this->rootScope = $this->rootSpan->activate();
        $this->spanCounter = 0;
    }

    public function recordSpan(string $name, string $body, string $type, string $subtype): void
    {
        if ($this->spanCounter >= self::MAX_SPANS) {
            return;
        }

        $attributes = $this->attributeExtractor->fromMessageBody($body);

        try {
            $spanBuilder = $this->tracer->spanBuilder($name)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setAttribute('messaging.system', $subtype)
                ->setAttribute('span.type', $type);

            foreach ($attributes as $key => $value) {
                $spanBuilder->setAttribute($key, $value);
            }

            $this->spans[$name] = $spanBuilder->startSpan();
            $this->spanCounter++;
        } catch (Throwable) {
        }
    }

    public function stopSpan(string $name): void
    {
        if (isset($this->spans[$name])) {
            $this->spans[$name]->end();
            unset($this->spans[$name]);
        }
    }

    public function end(string $status): void
    {
        if ($this->rootSpan !== null) {
            if ($status === 'OK') {
                $this->rootSpan->setStatus(StatusCode::STATUS_OK);
            } else {
                $this->rootSpan->setStatus(StatusCode::STATUS_ERROR, $status);
            }

            if ($this->rootScope !== null) {
                $this->rootScope->detach();
                $this->rootScope = null;
            }

            $this->rootSpan->end();
            $this->rootSpan = null;

            $this->tracerProvider->forceFlush();
        }
    }

    public function registerError(Throwable $error): void
    {
        if ($this->rootSpan !== null) {
            $this->rootSpan->recordException($error);
            $this->rootSpan->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
        }
    }
}
