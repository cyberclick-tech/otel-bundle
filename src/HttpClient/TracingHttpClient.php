<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle\HttpClient;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class TracingHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly TracerInterface $tracer,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? 'unknown';
        $path = $parsedUrl['path'] ?? '/';

        $span = $this->tracer->spanBuilder(sprintf('HTTP %s %s%s', $method, $host, $path))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.method', $method)
            ->setAttribute('http.url', $url)
            ->setAttribute('http.host', $host)
            ->startSpan();

        $originalOnProgress = $options['on_progress'] ?? null;
        $spanClosed = false;

        $options['on_progress'] = function (int $dlNow, int $dlSize, array $info) use ($span, $originalOnProgress, &$spanClosed) {
            if (!$spanClosed && ($info['http_code'] ?? 0) > 0) {
                $statusCode = $info['http_code'];
                $span->setAttribute('http.status_code', $statusCode);
                $span->setAttribute('http.response.status_code', $statusCode);

                if ($statusCode >= 400) {
                    $span->setStatus(StatusCode::STATUS_ERROR, sprintf('HTTP %d', $statusCode));
                } else {
                    $span->setStatus(StatusCode::STATUS_OK);
                }

                $span->end();
                $spanClosed = true;
            }

            if ($originalOnProgress !== null) {
                $originalOnProgress($dlNow, $dlSize, $info);
            }
        };

        try {
            return $this->client->request($method, $url, $options);
        } catch (\Throwable $e) {
            if (!$spanClosed) {
                $span->recordException($e);
                $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
                $span->end();
            }

            throw $e;
        }
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new static($this->client->withOptions($options), $this->tracer);
    }
}
