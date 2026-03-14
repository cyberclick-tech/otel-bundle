<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle\Tracing;

use Throwable;

interface MessageTracerInterface
{
    public function start(string $queue, ?string $body = null): void;

    public function recordSpan(string $name, string $body, string $type, string $subtype): void;

    public function stopSpan(string $name): void;

    public function end(string $status): void;

    public function registerError(Throwable $error): void;
}
