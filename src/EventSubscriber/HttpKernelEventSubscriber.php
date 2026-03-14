<?php
declare(strict_types=1);

namespace CyberclickTech\OtelBundle\EventSubscriber;

use CyberclickTech\OtelBundle\SpanAttributeExtractorInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

final class HttpKernelEventSubscriber implements EventSubscriberInterface
{
    private RouterInterface $router;
    private TracerInterface $tracer;
    private SpanAttributeExtractorInterface $attributeExtractor;

    /** @var array<int, SpanInterface> */
    private array $rootSpans;
    /** @var array<int, ScopeInterface> */
    private array $rootScopes;
    /** @var array<int, SpanInterface> */
    private array $controllerSpans;

    public function __construct(
        RouterInterface $router,
        TracerInterface $tracer,
        SpanAttributeExtractorInterface $attributeExtractor,
    ) {
        $this->router = $router;
        $this->tracer = $tracer;
        $this->attributeExtractor = $attributeExtractor;

        $this->rootSpans = [];
        $this->rootScopes = [];
        $this->controllerSpans = [];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4096],
            KernelEvents::CONTROLLER => ['onKernelController'],
            KernelEvents::RESPONSE => ['onKernelResponse'],
            KernelEvents::TERMINATE => ['onKernelTerminate'],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $requestId = $this->requestId($event->getRequest());
        $request = $event->getRequest();

        $span = $this->tracer->spanBuilder(\sprintf('%s %s', $request->getMethod(), $request->getPathInfo()))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.method', $request->getMethod())
            ->setAttribute('http.url', $request->getUri())
            ->setAttribute('http.target', $request->getPathInfo())
            ->setAttribute('http.host', $request->getHost())
            ->startSpan();

        $this->rootSpans[$requestId] = $span;
        $this->rootScopes[$requestId] = $span->activate();
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $requestId = $this->requestId($event->getRequest());
        $request = $event->getRequest();

        if (\array_key_exists($requestId, $this->rootSpans)) {
            $rootSpan = $this->rootSpans[$requestId];
            $routePath = $this->getRoutePath($request);
            $rootSpan->updateName(\sprintf('%s %s', $request->getMethod(), $routePath));
            $rootSpan->setAttribute('http.route', $routePath);

            foreach ($this->attributeExtractor->fromRequest($request) as $key => $value) {
                $rootSpan->setAttribute($key, $value);
            }
        }

        $name = $this->getCallableName($event->getController());

        try {
            $this->controllerSpans[$requestId] = $this->tracer->spanBuilder($name)
                ->setAttribute('code.function', $name)
                ->startSpan();
        } catch (Throwable) {
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $requestId = $this->requestId($event->getRequest());

        if (false === \array_key_exists($requestId, $this->controllerSpans)) {
            return;
        }

        $this->controllerSpans[$requestId]->end();
        unset($this->controllerSpans[$requestId]);
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $requestId = $this->requestId($event->getRequest());

        if (false === \array_key_exists($requestId, $this->rootSpans)) {
            return;
        }

        $rootSpan = $this->rootSpans[$requestId];
        $statusCode = $event->getResponse()->getStatusCode();

        $rootSpan->setAttribute('http.status_code', $statusCode);
        $rootSpan->setAttribute('http.response.status_code', $statusCode);

        if ($statusCode >= 400) {
            $rootSpan->setStatus(StatusCode::STATUS_ERROR, \sprintf('HTTP %sxx', \substr((string) $statusCode, 0, 1)));
        } else {
            $rootSpan->setStatus(StatusCode::STATUS_OK);
        }

        if (isset($this->rootScopes[$requestId])) {
            $this->rootScopes[$requestId]->detach();
            unset($this->rootScopes[$requestId]);
        }

        $rootSpan->end();
        unset($this->rootSpans[$requestId]);
    }

    private function requestId(Request $request): int
    {
        return \spl_object_id($request);
    }

    private function getCallableName($callable): string
    {
        if ($callable instanceof \Closure) {
            return 'closure';
        }

        if (true === \is_string($callable)) {
            return \trim($callable);
        }

        if (true === \is_array($callable)) {
            $class = \is_object($callable[0])
                ? \get_class($callable[0])
                : \trim($callable[0]);
            $method = \trim($callable[1]);

            return \sprintf('%s::%s', $class, $method);
        }

        if (\is_callable($callable) && \is_object($callable)) {
            return \sprintf('%s::%s', \get_class($callable), '__invoke');
        }

        return 'unknown';
    }

    private function getRoutePath(Request $request): string
    {
        $routeName = $request->attributes->get('_route');
        $route = $this->router->getRouteCollection()->get($routeName);

        return null !== $route ? $route->getPath() : 'unknown';
    }
}
