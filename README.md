# cyberclick/otel-bundle

Symfony bundle for OpenTelemetry instrumentation. Provides automatic tracing for HTTP requests, outgoing HTTP calls, console commands, Doctrine queries, and Symfony Messenger messages, plus log-trace correlation via Monolog.

## Installation

```bash
composer require cyberclick/otel-bundle
```

Register the bundle in `config/bundles.php`:

```php
Cyberclick\OtelBundle\CyberclickOtelBundle::class => ['all' => true],
```

## Configuration

Set the required environment variables:

```env
OTEL_SERVICE_NAME=myapp
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
```

Optional:

```env
OTEL_DB_INSTANCE=mydb  # Database name shown in DB spans (defaults to empty)
```

## What it does out of the box

- **HTTP tracing**: Creates spans for every incoming HTTP request with `http.method`, `http.route`, `http.host`, `http.status_code`
- **Console tracing**: Creates spans for console commands with exit codes
- **Log correlation**: Injects `trace_id` and `span_id` into Monolog log records via a processor

## Optional instrumentation

The following features require explicit configuration in your app's services:

### HTTP client tracing

Traces outgoing HTTP calls made via Symfony HttpClient. You choose which clients to instrument by decorating them in your services config:

```yaml
# Trace ALL outgoing HTTP calls
Cyberclick\OtelBundle\HttpClient\TracingHttpClient:
    decorates: http_client
    arguments:
        $client: '@.inner'
        $tracer: '@cyberclick_otel.tracer'
```

```yaml
# Trace only a specific scoped client
Cyberclick\OtelBundle\HttpClient\TracingHttpClient:
    decorates: http_client.catastro
    arguments:
        $client: '@.inner'
        $tracer: '@cyberclick_otel.tracer'
```

Spans are created with `http.method`, `http.url`, `http.host`, `http.status_code`.

### Doctrine tracing

Creates spans for every SQL query with `db.system`, `db.name`, `db.statement`, `db.operation`.

If you use Doctrine's standard configuration, add the middleware to your DBAL connection:

```yaml
doctrine:
    dbal:
        connections:
            default:
                middlewares:
                    - 'Cyberclick\OtelBundle\Doctrine\TracingMiddleware'
```

If you use a custom `EntityManagerFactory`, inject the middleware and pass it to `setMiddlewares()`:

```php
use Cyberclick\OtelBundle\Doctrine\TracingMiddleware;
use Doctrine\DBAL\Driver\Middleware;

public static function create(array $parameters, ?Middleware $tracingMiddleware = null): EntityManager
{
    $dbalConfig = new \Doctrine\DBAL\Configuration();
    if ($tracingMiddleware !== null) {
        $dbalConfig->setMiddlewares([$tracingMiddleware]);
    }
    // ...
}
```

```yaml
# services.yaml
App\EntityManagerFactory:
    arguments:
        $tracingMiddleware: '@Cyberclick\OtelBundle\Doctrine\TracingMiddleware'
```

### Messenger middleware

Traces Symfony Messenger message handling:

```yaml
framework:
    messenger:
        buses:
            command.bus:
                middleware:
                    - cyberclick_otel.messenger.tracing_middleware
```

### Message tracer (RabbitMQ consumers)

`MessageTracerInterface` provides manual span management for RabbitMQ consumers with automatic `forceFlush()` for long-running processes:

```php
use Cyberclick\OtelBundle\Tracing\MessageTracerInterface;

final class MyConsumer
{
    public function __construct(private MessageTracerInterface $tracer) {}

    public function consume(string $queueName, string $body): void
    {
        $this->tracer->start($queueName, $body);
        $this->tracer->recordSpan('my_event', $body, 'domain_event', 'consume');

        try {
            // process message...
            $this->tracer->stopSpan('my_event');
            $this->tracer->end('OK');
        } catch (\Throwable $e) {
            $this->tracer->registerError($e);
            $this->tracer->stopSpan('my_event');
            $this->tracer->end('KO');
        }
    }
}
```

## Custom span attributes

To add application-specific attributes to HTTP and message spans (e.g., tenant ID, user ID), implement `SpanAttributeExtractorInterface`:

```php
use Cyberclick\OtelBundle\SpanAttributeExtractorInterface;
use Symfony\Component\HttpFoundation\Request;

final class MyAppSpanAttributeExtractor implements SpanAttributeExtractorInterface
{
    /**
     * Attributes added to the root HTTP span (called during kernel.controller).
     */
    public function fromRequest(Request $request): array
    {
        return [
            'enduser.id' => $request->attributes->get('authenticated_uid'),
            'tenant.id' => $request->get('tenantId'),
        ];
    }

    /**
     * Attributes added to RabbitMQ consumer spans.
     */
    public function fromMessageBody(?string $body): array
    {
        $data = json_decode($body ?? '{}');
        return [
            'tenant.id' => $data?->data?->attributes?->tenant_id ?? null,
        ];
    }
}
```

Register it in your services config to override the default (no-op) extractor:

```yaml
Actel\Shared\Infrastructure\OpenTelemetry\MyAppSpanAttributeExtractor: ~

Cyberclick\OtelBundle\SpanAttributeExtractorInterface:
    alias: Actel\Shared\Infrastructure\OpenTelemetry\MyAppSpanAttributeExtractor
```

## Service IDs

| Service | Description |
|---|---|
| `cyberclick_otel.tracer_provider` | `TracerProvider` instance |
| `cyberclick_otel.tracer` | `TracerInterface` instance |
| `cyberclick_otel.messenger.tracing_middleware` | Messenger middleware |
| `Cyberclick\OtelBundle\Tracing\MessageTracerInterface` | Message tracer for consumers |
| `Cyberclick\OtelBundle\Doctrine\TracingMiddleware` | Doctrine DBAL middleware |
| `Cyberclick\OtelBundle\HttpClient\TracingHttpClient` | HTTP client decorator (manual registration) |

## Requirements

- PHP >= 8.2
- Symfony 7.x or 8.x
- OpenTelemetry PHP SDK
