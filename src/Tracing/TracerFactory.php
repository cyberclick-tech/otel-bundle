<?php
declare(strict_types=1);

namespace Cyberclick\OtelBundle\Tracing;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;

final class TracerFactory
{
    public function __invoke(
        string $serviceName,
        string $otlpEndpoint,
        string $env,
        string $serviceVersion = '1.0',
    ): TracerProvider {
        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                ServiceAttributes::SERVICE_NAME => $serviceName,
                ServiceAttributes::SERVICE_VERSION => $serviceVersion,
                'deployment.environment' => $env,
            ]))
        );

        $transport = (new OtlpHttpTransportFactory())->create(
            $otlpEndpoint . '/v1/traces',
            ContentTypes::PROTOBUF,
        );
        $exporter = new SpanExporter($transport);

        $provider = new TracerProvider(
            new BatchSpanProcessor($exporter, Clock::getDefault()),
            null,
            $resource,
        );

        register_shutdown_function([$provider, 'shutdown']);

        return $provider;
    }
}
