<?php
declare(strict_types=1);

namespace Cyberclick\OtelBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use OpenTelemetry\API\Trace\TracerInterface;

final class TracingMiddleware implements Middleware
{
    public function __construct(
        private readonly TracerInterface $tracer,
        private readonly string $instance,
        private readonly string $engine = 'mysql',
        private readonly bool $debugMode = false,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TracingDriver($driver, $this->tracer, $this->instance, $this->engine, $this->debugMode);
    }
}
