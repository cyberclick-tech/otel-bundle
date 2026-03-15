<?php
declare(strict_types=1);

namespace Cyberclick\OtelBundle\EventSubscriber;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final class ConsoleEventSubscriber implements EventSubscriberInterface
{
    private TracerInterface $tracer;
    /** @var string[] */
    private array $skippedCommands;

    /** @var array<int, SpanInterface> */
    private array $rootSpans;
    /** @var array<int, ScopeInterface> */
    private array $rootScopes;
    /** @var array<int, SpanInterface> */
    private array $childSpans;

    /** @param string[] $skippedCommands */
    public function __construct(TracerInterface $tracer, array $skippedCommands = ['rabbitmq:consume'])
    {
        $this->tracer = $tracer;
        $this->skippedCommands = $skippedCommands;
        $this->rootSpans = [];
        $this->rootScopes = [];
        $this->childSpans = [];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onConsoleCommandEvent'],
            ConsoleEvents::TERMINATE => ['onConsoleTerminateEvent'],
            ConsoleEvents::ERROR => ['onConsoleErrorEvent'],
        ];
    }

    public function onConsoleCommandEvent(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if ($this->shouldSkip($command)) {
            return;
        }

        $input = $event->getInput();
        $queueArgument = '';
        try {
            $queueArgument = ' ' . $input->getArgument('queue');
        } catch (Throwable) {
        }

        $key = $this->transactionKey($command);
        $spanName = $command->getName() . $queueArgument;

        if (0 !== \count($this->rootSpans)) {
            try {
                $this->childSpans[$key] = $this->tracer->spanBuilder($spanName)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->startSpan();
            } catch (Throwable) {
            }
        }

        $span = $this->tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute('console.command', $command->getName())
            ->startSpan();

        $this->rootSpans[$key] = $span;
        $this->rootScopes[$key] = $span->activate();
    }

    public function onConsoleTerminateEvent(ConsoleTerminateEvent $event): void
    {
        $command = $event->getCommand();

        if ($this->shouldSkip($command)) {
            return;
        }

        $key = $this->transactionKey($event->getCommand());

        if (!\array_key_exists($key, $this->rootSpans)) {
            return;
        }

        $exitCode = $event->getExitCode();
        $rootSpan = $this->rootSpans[$key];

        $rootSpan->setAttribute('process.exit_code', $exitCode);

        if ($exitCode === 0) {
            $rootSpan->setStatus(StatusCode::STATUS_OK);
        } else {
            $rootSpan->setStatus(StatusCode::STATUS_ERROR, (string) $exitCode);
        }

        if (isset($this->rootScopes[$key])) {
            $this->rootScopes[$key]->detach();
            unset($this->rootScopes[$key]);
        }

        $rootSpan->end();
        unset($this->rootSpans[$key]);

        if (true === \array_key_exists($key, $this->childSpans)) {
            $this->childSpans[$key]->end();
            unset($this->childSpans[$key]);
        }
    }

    public function onConsoleErrorEvent(ConsoleErrorEvent $event): void
    {
        if ($this->shouldSkip($event->getCommand())) {
            return;
        }

        $key = $this->transactionKey($event->getCommand());

        if (\array_key_exists($key, $this->rootSpans)) {
            $this->rootSpans[$key]->recordException($event->getError());
            $this->rootSpans[$key]->setStatus(StatusCode::STATUS_ERROR, $event->getError()->getMessage());
        }
    }

    private function shouldSkip(?Command $command): bool
    {
        if ($command === null) {
            return true;
        }

        foreach ($this->skippedCommands as $skipped) {
            if (str_contains($command->getName(), $skipped)) {
                return true;
            }
        }

        return false;
    }

    private function transactionKey(Command $command): int
    {
        return \spl_object_id($command);
    }
}
