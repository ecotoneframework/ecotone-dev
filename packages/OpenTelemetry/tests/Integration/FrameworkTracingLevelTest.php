<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Api\Tracing\RecordedProcess;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\LicenceTesting;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class FrameworkTracingLevelTest extends TracingTestCase
{
    public function test_the_framework_level_records_the_execution_of_a_flow_traced_through_open_telemetry(): void
    {
        $ecotone = $this->placedAndNotified(new InMemoryExporter(), TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework));

        $processes = $ecotone->popExecutionRecording()->processes;

        self::assertSame(
            ['send command order.place, class@anonymous::place', 'receive notifications, class@anonymous::notify'],
            array_map(fn (RecordedProcess $process): string => $process->title, $processes),
        );
        self::assertSame(1, $processes[1]->sentBy?->processNumber);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function flows(): iterable
    {
        foreach (['in flow testing' => false, 'in a running application' => true] as $where => $running) {
            yield 'a synchronous command ' . $where => ['commandHandled', $running];
            yield 'an event handled asynchronously ' . $where => ['placedAndNotified', $running];
            yield 'a command whose handler fails ' . $where => ['refused', $running];
            yield 'a distributed command ' . $where => ['distributed', $running];
        }
    }

    #[DataProvider('flows')]
    public function test_at_the_framework_level_open_telemetry_exports_each_application_span_as_at_the_application_level_with_the_steps_between_them(string $flow, bool $running): void
    {
        $application = $this->exported($flow, $running, TracingConfiguration::createWithDefaults());
        $framework = $this->exported($flow, $running, TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework));

        self::assertNotEmpty($application);
        self::assertSame([], self::stepsIn($application));
        self::assertNotSame([], self::stepsIn($framework));
        self::assertSame(self::applicationShapeOf($application), self::applicationShapeOf($framework));
    }

    #[DataProvider('flows')]
    public function test_at_the_framework_level_every_step_is_exported_in_one_trace_under_the_open_step_or_span_in_the_order_it_ran(string $flow, bool $running): void
    {
        $spans = $this->exported($flow, $running, TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework));
        $byId = [];
        foreach ($spans as $span) {
            $byId[$span->getSpanId()] = $span;
        }
        $orderOf = fn (ImmutableSpan $span): ?int => $span->getAttributes()->get('ecotone.execution.order');
        $childrenOf = [];
        foreach (self::stepsIn($spans) as $step) {
            if (! $step->getParentContext()->isValid()) {
                continue;
            }
            self::assertArrayHasKey($step->getParentSpanId(), $byId);
            $childrenOf[$step->getParentSpanId()][] = $step;
        }
        foreach ($childrenOf as $parentId => $children) {
            $parentOrder = $orderOf($byId[$parentId]);
            $inStartOrder = $children;
            usort($inStartOrder, fn (ImmutableSpan $first, ImmutableSpan $second): int => [$first->getStartEpochNanos(), $orderOf($first)] <=> [$second->getStartEpochNanos(), $orderOf($second)]);
            $inRunOrder = $children;
            usort($inRunOrder, fn (ImmutableSpan $first, ImmutableSpan $second): int => $orderOf($first) <=> $orderOf($second));
            self::assertSame(array_map(fn (ImmutableSpan $span): string => $span->getSpanId(), $inRunOrder), array_map(fn (ImmutableSpan $span): string => $span->getSpanId(), $inStartOrder));
            if ($parentOrder !== null) {
                self::assertGreaterThan($parentOrder, $orderOf($inRunOrder[0]));
            }
        }
        self::assertCount(1, array_unique(array_map(fn (ImmutableSpan $span): string => $span->getTraceId(), $spans)));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function places(): iterable
    {
        yield 'in flow testing' => [false];
        yield 'in a running application' => [true];
    }

    #[DataProvider('places')]
    public function test_a_failing_handler_at_the_framework_level_exports_its_failed_steps_with_an_error_status_and_leaves_no_span_open(bool $running): void
    {
        $exporter = new InMemoryExporter();
        $handlers = new class () {
            #[CommandHandler('order.refuse')]
            public function refuse(string $order): void
            {
                throw new RuntimeException('the order is refused');
            }

            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
            }
        };
        $ecotone = $this->application($running, [$handlers], $exporter, TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework));
        try {
            $ecotone->getCommandBus()->sendWithRouting('order.refuse', 'coffee');
        } catch (RuntimeException) {
        }
        $afterTheFailure = count($exporter->getSpans());
        $ecotone->getCommandBus()->sendWithRouting('order.place', 'coffee');
        $failed = array_slice($exporter->getSpans(), 0, $afterTheFailure);
        $next = array_slice($exporter->getSpans(), $afterTheFailure);

        self::assertContains('handler class@anonymous::refuse', array_map(fn (ImmutableSpan $span): string => $span->getName(), array_filter(self::stepsIn($failed), fn (ImmutableSpan $span): bool => $span->getStatus()->getCode() === StatusCode::STATUS_ERROR)));
        self::assertNotContains($failed[0]->getTraceId(), array_map(fn (ImmutableSpan $span): string => $span->getTraceId(), $next));
        self::assertCount(1, array_filter($next, fn (ImmutableSpan $span): bool => ! $span->getParentContext()->isValid()));
    }

    /**
     * @return iterable<string, array{TracingConfiguration, bool}>
     */
    public static function levels(): iterable
    {
        yield 'application level' => [TracingConfiguration::createWithDefaults(), false];
        yield 'framework level' => [TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework), true];
    }

    #[DataProvider('levels')]
    public function test_a_running_application_with_open_telemetry_keeps_its_memory_flat_over_fully_consumed_messages_whether_or_not_it_exports_steps(TracingConfiguration $tracing, bool $exportsSteps): void
    {
        $exporter = new InMemoryExporter();
        $application = $this->runningApplication($exporter, $tracing);
        $memoryAfter = [];
        $stepsExported = 0;
        foreach ([1, 2, 3, 4] as $round) {
            for ($order = 0; $order < 300; $order++) {
                $application->getCommandBus()->sendWithRouting('order.place', 'coffee');
            }
            $application->run('notifications', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 300, executionTimeLimitInMilliseconds: 600000));
            $stepsExported += count(self::stepsIn($exporter->getSpans()));
            $exporter->getStorage()->exchangeArray([]);
            gc_collect_cycles();
            $memoryAfter[$round] = memory_get_usage();
        }

        self::assertSame($exportsSteps, $stepsExported > 0);
        self::assertLessThan(64 * 1024, $memoryAfter[4] - $memoryAfter[2]);
    }

    public function test_a_running_application_whose_consumed_messages_all_fail_ends_every_step_span_it_starts(): void
    {
        $exporter = new InMemoryExporter();
        $handlers = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'notify')]
            public function notify(string $order): void
            {
                throw new RuntimeException('the notification failed');
            }
        };
        $application = $this->application(true, [$handlers], $exporter, TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework), [SimpleMessageChannelBuilder::createQueueChannel('notifications'), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('nullChannel')]);
        $consume = function (int $messages) use ($application, $exporter): array {
            for ($order = 0; $order < $messages; $order++) {
                $application->getCommandBus()->sendWithRouting('order.place', 'coffee');
            }
            $application->run('notifications', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: $messages, executionTimeLimitInMilliseconds: 600000, stopOnError: false));
            $steps = self::stepsIn($exporter->getSpans());
            $exporter->getStorage()->exchangeArray([]);

            return [count($steps), count(array_filter($steps, fn (ImmutableSpan $span): bool => $span->getStatus()->getCode() === StatusCode::STATUS_ERROR))];
        };

        [$stepsOfOneMessage, $failedStepsOfOneMessage] = $consume(1);

        self::assertGreaterThan(0, $failedStepsOfOneMessage);
        foreach ([1, 2, 3] as $round) {
            self::assertSame([200 * $stepsOfOneMessage, 200 * $failedStepsOfOneMessage], $consume(200));
        }
    }

    /**
     * @return list<ImmutableSpan>
     */
    private function exported(string $flow, bool $running, TracingConfiguration $tracing): array
    {
        $exporter = new InMemoryExporter();
        $this->{$flow}($exporter, $tracing, $running);

        return $exporter->getSpans();
    }

    /**
     * @param list<object> $handlers
     * @param list<object> $extensionObjects
     */
    private function application(bool $running, array $handlers, InMemoryExporter $exporter, TracingConfiguration $tracing, array $extensionObjects = []): ConfiguredMessagingSystem|FlowTestSupport
    {
        $classes = array_map(fn (object $handler): string => $handler::class, $handlers);
        $services = [...$handlers, TracerProviderInterface::class => self::prepareTracer($exporter)];
        $extensionObjects = [...$extensionObjects, $tracing];

        return $running
            ? EcotoneLite::bootstrap($classes, $services, $extensionObjects, enabledModules: [ModulePackageList::TRACING_PACKAGE], licenceKey: LicenceTesting::VALID_LICENCE)
            : EcotoneLite::bootstrapFlowTesting($classes, $services, $extensionObjects, enabledModules: [ModulePackageList::TRACING_PACKAGE], licenceKey: LicenceTesting::VALID_LICENCE);
    }

    private function commandHandled(InMemoryExporter $exporter, TracingConfiguration $tracing, bool $running): void
    {
        $handlers = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order): void
            {
            }
        };
        $this->application($running, [$handlers], $exporter, $tracing)->getCommandBus()->sendWithRouting('order.place', 'coffee');
    }

    private function distributed(InMemoryExporter $exporter, TracingConfiguration $tracing, bool $running): void
    {
        $tickets = new class () {
            #[Distributed]
            #[CommandHandler('ticket.create', endpointId: 'ticket.creation')]
            public function create(string $ticket): void
            {
            }
        };
        $channel = SimpleMessageChannelBuilder::createQueueChannel('distributed_tickets');
        $registration = new class () {
            #[CommandHandler('user.register')]
            public function register(string $user): void
            {
            }
        };
        $users = $this->application($running, [$registration], $exporter, $tracing, [$channel, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'distributed_tickets'), ServiceConfiguration::createWithDefaults()->withServiceName('user_service')]);
        $ticketService = $this->application($running, [$tickets], $exporter, $tracing, [$channel, ServiceConfiguration::createWithDefaults()->withServiceName('ticket_service')]);
        $users->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'User changed billing address');
        $ticketService->run('distributed_tickets', ExecutionPollingMetadata::createWithTestingSetup());
    }

    /**
     * @param list<ImmutableSpan> $spans
     * @return list<ImmutableSpan>
     */
    private static function stepsIn(array $spans): array
    {
        return array_values(array_filter($spans, fn (ImmutableSpan $span): bool => $span->getAttributes()->has('ecotone.execution.step')));
    }

    /**
     * @param list<ImmutableSpan> $spans
     * @return list<array<string, mixed>>
     */
    private static function applicationShapeOf(array $spans): array
    {
        $byId = [];
        foreach ($spans as $span) {
            $byId[$span->getSpanId()] = $span;
        }
        $tracedParentOf = function (ImmutableSpan $span) use ($byId): ?string {
            $parent = $byId[$span->getParentSpanId()] ?? null;
            while ($parent !== null && $parent->getAttributes()->has('ecotone.execution.step')) {
                $parent = $byId[$parent->getParentSpanId()] ?? null;
            }

            return $parent?->getName();
        };
        $stable = fn (mixed $value): mixed => is_string($value) ? (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\b[0-9a-f]{32}\b|\b[0-9a-f]{16}\b/', '{id}', $value) : $value;
        $ownAttributes = function (array $attributes) use ($stable): array {
            $kept = [];
            foreach ($attributes as $key => $value) {
                if (! str_starts_with((string) $key, 'ecotone.execution.')) {
                    $kept[$key] = in_array($key, ['timestamp', 'exception.stacktrace'], true) ? '{' . $key . '}' : $stable($value);
                }
            }

            return $kept;
        };

        $traces = [];
        foreach ($spans as $span) {
            if (! $span->getAttributes()->has('ecotone.execution.step')) {
                $traces[$span->getTraceId()] ??= count($traces);
            }
        }

        return array_values(array_map(fn (ImmutableSpan $span): array => [
            'trace' => $traces[$span->getTraceId()],
            'name' => $span->getName(),
            'kind' => $span->getKind(),
            'status' => $span->getStatus()->getCode(),
            'parent' => $tracedParentOf($span),
            'attributes' => $ownAttributes($span->getAttributes()->toArray()),
            'events' => array_map(fn ($event): array => [$stable($event->getName()), $ownAttributes($event->getAttributes()->toArray())], $span->getEvents()),
        ], array_filter($spans, fn (ImmutableSpan $span): bool => ! $span->getAttributes()->has('ecotone.execution.step'))));
    }

    private function runningApplication(InMemoryExporter $exporter, TracingConfiguration $tracing): ConfiguredMessagingSystem
    {
        $handlers = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'notify')]
            public function notify(string $order): void
            {
            }
        };

        return EcotoneLite::bootstrap(
            [$handlers::class],
            [$handlers, TracerProviderInterface::class => self::prepareTracer($exporter)],
            [SimpleMessageChannelBuilder::createQueueChannel('notifications'), $tracing],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function placedAndNotified(InMemoryExporter $exporter, TracingConfiguration $tracing, bool $running = false): ConfiguredMessagingSystem|FlowTestSupport
    {
        $handlers = new class () {
            #[CommandHandler('order.place')]
            public function place(string $order, EventBus $eventBus): void
            {
                $eventBus->publishWithRouting('order.placed', $order);
            }

            #[Asynchronous('notifications')]
            #[EventHandler('order.placed', endpointId: 'notify')]
            public function notify(string $order): void
            {
            }
        };
        $ecotone = $this->application($running, [$handlers], $exporter, $tracing, [SimpleMessageChannelBuilder::createQueueChannel('notifications')]);
        $ecotone->getCommandBus()->sendWithRouting('order.place', 'coffee');
        $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());

        return $ecotone;
    }

    private function refused(InMemoryExporter $exporter, TracingConfiguration $tracing, bool $running): void
    {
        $handlers = new class () {
            #[CommandHandler('order.refuse')]
            public function refuse(string $order): void
            {
                throw new RuntimeException('the order is refused');
            }
        };
        $ecotone = $this->application($running, [$handlers], $exporter, $tracing);
        try {
            $ecotone->getCommandBus()->sendWithRouting('order.refuse', 'coffee');
        } catch (RuntimeException) {
        }
    }
}
