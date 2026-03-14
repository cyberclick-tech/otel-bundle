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
        $spanName = sprintf('HTTP %s %s%s', $method, $host, $path);

        $span = $this->tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('http.method', $method)
            ->setAttribute('http.url', $url)
            ->setAttribute('http.host', $host)
            ->startSpan();

        try {
            $response = $this->client->request($method, $url, $options);

            return new TracingResponse($response, $span);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();

            throw $e;
        }
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof TracingResponse) {
            $responses = $responses->getInnerResponse();
        }

        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new static($this->client->withOptions($options), $this->tracer);
    }
}
