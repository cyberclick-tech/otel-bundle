<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle\HttpClient;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TracingResponse implements ResponseInterface
{
    private bool $spanEnded = false;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly SpanInterface $span,
    ) {
    }

    public function getStatusCode(): int
    {
        $statusCode = $this->response->getStatusCode();
        $this->finishSpan($statusCode);

        return $statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->response->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        try {
            $content = $this->response->getContent($throw);
            $this->finishSpan($this->response->getStatusCode());

            return $content;
        } catch (\Throwable $e) {
            $this->span->recordException($e);
            $this->span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $this->endSpan();

            throw $e;
        }
    }

    public function toArray(bool $throw = true): array
    {
        try {
            $data = $this->response->toArray($throw);
            $this->finishSpan($this->response->getStatusCode());

            return $data;
        } catch (\Throwable $e) {
            $this->span->recordException($e);
            $this->span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $this->endSpan();

            throw $e;
        }
    }

    public function cancel(): void
    {
        $this->response->cancel();
        $this->span->setStatus(StatusCode::STATUS_ERROR, 'cancelled');
        $this->endSpan();
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }

    public function getInnerResponse(): ResponseInterface
    {
        return $this->response;
    }

    private function finishSpan(int $statusCode): void
    {
        $this->span->setAttribute('http.status_code', $statusCode);
        $this->span->setAttribute('http.response.status_code', $statusCode);

        if ($statusCode >= 400) {
            $this->span->setStatus(StatusCode::STATUS_ERROR, sprintf('HTTP %d', $statusCode));
        } else {
            $this->span->setStatus(StatusCode::STATUS_OK);
        }

        $this->endSpan();
    }

    private function endSpan(): void
    {
        if (!$this->spanEnded) {
            $this->span->end();
            $this->spanEnded = true;
        }
    }

    public function __destruct()
    {
        $this->endSpan();
    }
}
