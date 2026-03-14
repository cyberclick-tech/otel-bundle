# cyberclick-tech/otel-bundle

Symfony bundle for OpenTelemetry instrumentation. Provides automatic tracing for HTTP requests, console commands, Doctrine queries, and Symfony Messenger messages, plus log-trace correlation via Monolog.

## Installation

```bash
composer require cyberclick-tech/otel-bundle
```

Register the bundle in `config/bundles.php`:

```php
CyberclickTech\OtelBundle\CyberclickOtelBundle::class => ['all' => true],
```

## Configuration

Set the required environment variables:

```env
OTEL_SERVICE_NAME=myapp
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
```

Optional:

```env
OTEL_DB_INSTANCE=mydb  # Database name shown in spans (defaults to empty)
```

## What it does out of the box

- **HTTP tracing**: Creates spans for every HTTP request with `http.method`, `http.route`, `http.host`, `http.status_code`
- **Console tracing**: Creates spans for console commands with exit codes
- **Doctrine tracing**: Creates spans for every SQL query with `db.system`, `db.name`, `db.statement`, `db.operation`
- **Messenger tracing**: Creates spans for Symfony Messenger message handling
- **Log correlation**: Injects `trace_id` and `span_id` into Monolog log records via a processor
- **Message tracing**: `MessageTracerInterface` for tracing RabbitMQ consumer messages with automatic `forceFlush()` for long-running processes

## Custom span attributes

To add application-specific attributes to spans (e.g., tenant ID, user ID), implement `SpanAttributeExtractorInterface`:

```php
use CyberclickTech\OtelBundle\SpanAttributeExtractorInterface;
use Symfony\Component\HttpFoundation\Request;

final class MyAppSpanAttributeExtractor implements SpanAttributeExtractorInterface
{
    public function fromRequest(Request $request): array
    {
        return [
            'enduser.id' => $request->attributes->get('authenticated_uid'),
            'tenant.id' => $request->get('tenantId'),
        ];
    }

    public function fromMessageBody(?string $body): array
    {
        $data = json_decode($body ?? '{}');
        return [
            'tenant.id' => $data?->tenant_id,
        ];
    }
}
```

Register it in your services config:

```yaml
CyberclickTech\OtelBundle\SpanAttributeExtractorInterface:
    alias: App\Infrastructure\MyAppSpanAttributeExtractor
```

## Messenger middleware

Add the tracing middleware to your messenger buses:

```yaml
framework:
    messenger:
        buses:
            command.bus:
                middleware:
                    - cyberclick_otel.messenger.tracing_middleware
```

## Doctrine middleware

To enable SQL tracing, add the Doctrine middleware to your DBAL connection config or register it manually in your entity manager factory.

## Service IDs

| Service | Description |
|---|---|
| `cyberclick_otel.tracer_provider` | `TracerProvider` instance |
| `cyberclick_otel.tracer` | `TracerInterface` instance |
| `cyberclick_otel.messenger.tracing_middleware` | Messenger middleware |
| `CyberclickTech\OtelBundle\Tracing\MessageTracerInterface` | Message tracer for consumers |
| `CyberclickTech\OtelBundle\Doctrine\TracingMiddleware` | Doctrine DBAL middleware |

## Requirements

- PHP >= 8.2
- Symfony 6.4 or 7.x
- OpenTelemetry PHP SDK
