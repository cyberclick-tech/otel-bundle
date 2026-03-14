<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

final class OtelMonologProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $span = Span::getCurrent();
        $context = $span->getContext();

        $traceId = $context->getTraceId();
        $spanId = $context->getSpanId();

        if ($traceId === '00000000000000000000000000000000') {
            return $record;
        }

        return $record->with(extra: array_merge($record->extra, [
            'trace_id' => $traceId,
            'span_id' => $spanId,
        ]));
    }
}
