<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class DbalChannelTracingTest extends DbalMessagingTestCase
{
    public function tearDown(): void
    {
        ChannelOutageConnection::$databaseDown = false;
        parent::tearDown();
    }

    public function test_a_send_that_fails_because_the_database_is_down_fails_its_span_and_the_next_flow_starts_a_fresh_root(): void
    {
        $recorder = new InMemorySpanRecorder();
        $ecotone = $this->bootstrap($recorder, [$this->asynchronousOrders()], [DbalBackedMessageChannelBuilder::create('orders')]);

        ChannelOutageConnection::$databaseDown = true;
        $this->assertNotNull($this->failureOf(fn () => $ecotone->sendCommandWithRouting('order.place', '1')));
        ChannelOutageConnection::$databaseDown = false;

        $this->assertSame(SpanStatus::Error, self::singleSpanNamed('send orders', $recorder)->status);
        $recorder->clear();
        $ecotone->sendCommandWithRouting('order.place', '2');
        $this->assertSame('send command', self::rootSpan($recorder)->name);
    }

    public function test_a_failing_handler_whose_error_channel_cannot_store_the_message_still_ends_every_span(): void
    {
        $recorder = new InMemorySpanRecorder();
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
                ChannelOutageConnection::$databaseDown = true;

                throw new RuntimeException('Handler failure');
            }
        };
        $ecotone = $this->bootstrap($recorder, [$orders], [
            DbalBackedMessageChannelBuilder::create('orders'),
            DbalBackedMessageChannelBuilder::create('error_channel'),
            ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('error_channel'),
        ]);
        $ecotone->sendCommandWithRouting('order.place', '1');
        $recorder->clear();

        $this->failureOf(fn () => $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false)));
        ChannelOutageConnection::$databaseDown = false;

        self::assertEverySpanNamedHasStatus('process class@anonymous::place', SpanStatus::Error, $recorder);
        self::assertEverySpanNamedHasStatus('send error_channel', SpanStatus::Error, $recorder);
        $recorder->clear();
        $ecotone->sendCommandWithRouting('order.place', '2');
        $this->assertSame('send command', self::rootSpan($recorder)->name);
    }

    public function test_a_distributed_send_that_fails_because_the_database_is_down_fails_its_span_and_leaves_no_open_span_behind(): void
    {
        $recorder = new InMemorySpanRecorder();
        $orders = new class () {
            #[CommandHandler('order.place')]
            public function place(string $orderId): void
            {
            }
        };
        $ecotone = $this->bootstrap($recorder, [$orders], [
            DbalBackedMessageChannelBuilder::create('distributed_channel'),
            DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'distributed_channel'),
            ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
        ]);

        ChannelOutageConnection::$databaseDown = true;
        $this->assertNotNull($this->failureOf(fn () => $ecotone->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'User changed billing address')));
        ChannelOutageConnection::$databaseDown = false;

        $this->assertSame(SpanStatus::Error, self::singleSpanNamed('send distributed', $recorder)->status);
        $recorder->clear();
        $ecotone->sendCommandWithRouting('order.place', '1');
        $this->assertSame('send command', self::rootSpan($recorder)->name);
    }

    public function test_a_consumer_receiving_a_message_sent_by_an_application_without_tracing_starts_its_own_trace(): void
    {
        $producer = $this->bootstrapWithoutTracing([$this->asynchronousOrders()]);
        $recorder = new InMemorySpanRecorder();
        $consumer = $this->bootstrap($recorder, [$this->asynchronousOrders()], [DbalBackedMessageChannelBuilder::create('orders')]);

        $producer->sendCommandWithRouting('order.place', '1');
        $consumer->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertTrue(self::singleSpanNamed('receive orders', $recorder)->isRoot());
    }

    public function test_an_invalid_traceparent_on_a_received_message_is_ignored_and_the_consumer_starts_its_own_trace(): void
    {
        $producer = $this->bootstrapWithoutTracing([$this->asynchronousOrders()]);
        $recorder = new InMemorySpanRecorder();
        $consumer = $this->bootstrap($recorder, [$this->asynchronousOrders()], [DbalBackedMessageChannelBuilder::create('orders')]);

        $producer->sendCommandWithRouting('order.place', '1', metadata: [MessageHeaders::TRACEPARENT => 'not-a-traceparent']);
        $consumer->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertTrue(self::singleSpanNamed('receive orders', $recorder)->isRoot());
    }

    /**
     * @param object[] $services
     * @param object[] $extensionObjects
     */
    private function bootstrap(InMemorySpanRecorder $recorder, array $services, array $extensionObjects): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            classesToResolve: array_map(fn (object $service): string => $service::class, $services),
            containerOrAvailableServices: [...$services, InMemorySpanRecorder::class => $recorder, DbalConnectionFactory::class => self::connectionThatCanGoDown()],
            extensionObjects: [TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class), ...$extensionObjects],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
    }

    /**
     * @param object[] $services
     */
    private function bootstrapWithoutTracing(array $services): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            classesToResolve: array_map(fn (object $service): string => $service::class, $services),
            containerOrAvailableServices: [...$services, DbalConnectionFactory::class => self::connectionThatCanGoDown()],
            extensionObjects: [DbalBackedMessageChannelBuilder::create('orders')],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
        )->setupDatabaseTables();
    }

    private static function connectionThatCanGoDown(): DbalConnectionFactory
    {
        return new DbalConnectionFactory([
            'dsn' => getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone',
            'connection' => ['wrapperClass' => ChannelOutageConnection::class],
        ]);
    }

    private function asynchronousOrders(): object
    {
        return new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.placing')]
            public function place(string $orderId): void
            {
            }
        };
    }

    private function failureOf(callable $action): ?Throwable
    {
        try {
            $action();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private static function singleSpanNamed(string $name, InMemorySpanRecorder $recorder): Span
    {
        $spans = array_values(array_filter($recorder->recordedSpans(), fn (Span $span): bool => $span->name === $name));
        self::assertCount(1, $spans, 'Expected exactly one span named ' . $name . ', got: ' . implode(', ', array_map(fn (Span $span): string => $span->name, $recorder->recordedSpans())));

        return $spans[0];
    }

    private static function assertEverySpanNamedHasStatus(string $name, SpanStatus $status, InMemorySpanRecorder $recorder): void
    {
        $spans = array_values(array_filter($recorder->recordedSpans(), fn (Span $span): bool => preg_replace('/^process class@anonymous\S*::/', 'process class@anonymous::', $span->name) === $name));
        self::assertNotEmpty($spans, 'Expected spans named ' . $name . ', got: ' . implode(', ', array_map(fn (Span $span): string => $span->name, $recorder->recordedSpans())));
        foreach ($spans as $span) {
            self::assertSame($status, $span->status, $name);
        }
    }

    private static function rootSpan(InMemorySpanRecorder $recorder): Span
    {
        $roots = array_values(array_filter($recorder->recordedSpans(), fn (Span $span): bool => $span->isRoot()));
        self::assertCount(1, $roots, 'Expected exactly one root span, got: ' . implode(', ', array_map(fn (Span $span): string => $span->name, $roots)));

        return $roots[0];
    }
}

final class ChannelOutageConnection extends Connection
{
    public static bool $databaseDown = false;

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        self::failWhenDown();

        return parent::executeQuery($sql, $params, $types, $qcp);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        self::failWhenDown();

        return parent::executeStatement($sql, $params, $types);
    }

    private static function failWhenDown(): void
    {
        if (self::$databaseDown) {
            throw new RuntimeException('The database is down');
        }
    }
}
