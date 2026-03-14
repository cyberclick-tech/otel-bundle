<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use OpenTelemetry\API\Trace\TracerInterface;

final class TracingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly TracerInterface $tracer,
        private readonly string $instance,
        private readonly string $engine,
        private readonly bool $debugMode,
    ) {
        parent::__construct($driver);
    }

    public function connect(array $params): Driver\Connection
    {
        return new TracingConnection(
            parent::connect($params),
            $this->tracer,
            $this->instance,
            $this->engine,
            $this->debugMode,
        );
    }
}
