<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use DateTimeImmutable;
use DateTimeZone;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use Ecotone\Test\StaticMessageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class PulseRequestBodyTest extends DbalMessagingTestCase
{
    private const TRACE = '0af7651916cd43dd8448eb211c80319c';

    private object $pulse;
    private object $handled;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public static function converters(): iterable
    {
        yield 'without JMS converter' => [[ModulePackageList::DBAL_PACKAGE]];
        yield 'with JMS converter' => [[ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]];
    }

    #[DataProvider('converters')]
    public function test_a_dead_letter_replay_sent_as_json_is_answered_with_the_replayed_count(array $enabledModules): void
    {
        $this->bootstrap($enabledModules);
        $this->deadLetter('m-1');

        $reply = $this->answer('ecotone.pulse.dead_letter.replay', '{"messageIds": ["m-1"]}');

        self::assertSame(['affected' => 1], $reply['deadLetterAction']);
        self::assertSame(['coffee'], $this->handled->payloads);
        self::assertSame(0, $this->orderService->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('converters')]
    public function test_a_dead_letter_delete_sent_as_json_is_answered_with_the_deleted_count(array $enabledModules): void
    {
        $this->bootstrap($enabledModules);
        $this->deadLetter('m-1');

        $reply = $this->answer('ecotone.pulse.dead_letter.delete', '{"filter": {"messageName": "order.place"}}');

        self::assertSame(['affected' => 1], $reply['deadLetterAction']);
        self::assertSame([], $this->handled->payloads);
        self::assertSame(0, $this->orderService->getGateway(DeadLetterGateway::class)->count());
    }

    #[DataProvider('converters')]
    public function test_a_projection_rebuild_sent_as_json_is_answered_with_the_rebuild(array $enabledModules): void
    {
        $this->bootstrap($enabledModules);

        $reply = $this->answer('ecotone.pulse.projections.rebuild', '{"projection": "orders"}', [MessageHeaders::TRACING_ID => self::TRACE]);

        self::assertSame(['tracingId' => self::TRACE, 'projection' => 'orders', 'mode' => 'synchronous'], $reply['rebuildAction']);
        self::assertSame(2, $this->orderService->sendQueryWithRouting('orders.count'));
    }

    #[DataProvider('converters')]
    public function test_a_projection_backfill_sent_as_json_is_answered_with_the_backfill(array $enabledModules): void
    {
        $this->bootstrap($enabledModules);

        $reply = $this->answer('ecotone.pulse.projections.backfill', '{"projection": "orders"}', [MessageHeaders::TRACING_ID => self::TRACE]);

        self::assertSame(['tracingId' => self::TRACE, 'projection' => 'orders', 'mode' => 'synchronous'], $reply['backfillAction']);
    }

    #[DataProvider('converters')]
    public function test_a_trackings_list_sent_as_json_is_answered_with_the_trackings(array $enabledModules): void
    {
        $this->bootstrap($enabledModules);
        $this->answer('ecotone.pulse.projections.rebuild', '{"projection": "orders"}', [MessageHeaders::TRACING_ID => self::TRACE]);

        $result = $this->query('ecotone.pulse.trackings.list', '{"since": "2026-10-13T12:00:00+00:00"}');

        self::assertSame([[self::TRACE, 'orders', 'done']], array_map(fn (array $tracking): array => [$tracking['tracingId'], $tracking['reference'], $tracking['state']], $result['trackings']));
    }

    #[DataProvider('converters')]
    public function test_a_trace_list_sent_as_json_is_answered_with_the_traces(array $enabledModules): void
    {
        $this->bootstrap($enabledModules);
        $this->orderService->sendCommandWithRouting('ticket.create', 'ticket-1');

        $result = $this->query('ecotone.pulse.traces.list', '{"flowType": "ticket.create"}');

        self::assertSame(1, $result['total']);
        self::assertSame('ticket.create', $result['flowType']);
        self::assertCount(1, $result['traces']);
    }

    #[DataProvider('converters')]
    public function test_a_trace_sent_as_json_is_answered_with_its_spans(array $enabledModules): void
    {
        $this->bootstrap($enabledModules);

        $result = $this->query('ecotone.pulse.traces.trace', '{"traceId": "' . self::TRACE . '"}');

        self::assertSame(self::TRACE, $result['traceId']);
        self::assertSame([], $result['spans']);
    }

    private function bootstrap(array $enabledModules): void
    {
        $this->handled = new class () {
            public array $payloads = [];

            #[InternalHandler('order.replayed')]
            public function replayed(string $payload): void
            {
                $this->payloads[] = $payload;
            }

            #[CommandHandler('ticket.create', endpointId: 'ticket.creating')]
            public function create(string $ticketId): void
            {
            }
        };
        $orders = new #[Projection('orders'), FromStream('orders_stream')] class () {
            public int $count = 0;

            #[EventHandler('order.placed')]
            public function whenPlaced(array $event): void
            {
                $this->count++;
            }

            #[QueryHandler('orders.count')]
            public function count(): int
            {
                return $this->count;
            }
        };
        $this->pulse = new class () {
            public array $snapshots = [];
            public array $queryResults = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }

            #[InternalHandler('ecotone.pulse.query_result_received')]
            public function queryResult(string $result, #[Header('ecotone_pulse_query_id')] string $queryId): void
            {
                $this->queryResults[$queryId] = $result;
            }
        };
        $channels = [SimpleMessageChannelBuilder::createQueueChannel('order_channel'), SimpleMessageChannelBuilder::createQueueChannel('pulse_channel')];
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->handled::class, $orders::class],
            containerOrAvailableServices: [$this->handled, $orders, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage(true),
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true, inMemory: true),
            ],
            enabledModules: $enabledModules,
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC'))),
        );
        $this->orderService->withEventStream('orders_stream', [Event::createWithType('order.placed', ['orderId' => '1']), Event::createWithType('order.placed', ['orderId' => '2'])]);
        $this->orderService->initializeProjection('orders');
        $this->pulseService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->pulse::class],
            containerOrAvailableServices: [$this->pulse],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'order_service', channelName: 'order_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('ecotone_pulse'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function deadLetter(string $messageId): void
    {
        $this->orderService->getGateway(DeadLetterGateway::class)->store(
            StaticMessageBuilder::withPayload('coffee')
                ->setMultipleHeaders([
                    MessageHeaders::MESSAGE_ID => $messageId,
                    MessageHeaders::CONTENT_TYPE => MediaType::TEXT_PLAIN,
                    MessageHeaders::POLLED_CHANNEL_NAME => 'order.replayed',
                    DistributedBusHeader::DISTRIBUTED_ROUTING_KEY => 'order.place',
                    ErrorContext::EXCEPTION_CLASS => 'RuntimeException',
                    ErrorContext::EXCEPTION_MESSAGE => 'boom',
                    ErrorContext::EXCEPTION_STACKTRACE => '#0 handler.php(1)',
                    ErrorContext::EXCEPTION_FILE => 'handler.php',
                    ErrorContext::EXCEPTION_LINE => 1,
                    ErrorContext::EXCEPTION_CODE => 0,
                ])
                ->build()
        );
    }

    private function answer(string $routingKey, string $payload, array $metadata = []): array
    {
        $received = count($this->pulse->snapshots);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', $routingKey, $payload, MediaType::APPLICATION_JSON, metadata: $metadata, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertCount($received + 1, $this->pulse->snapshots);

        return json_decode($this->pulse->snapshots[$received], true, flags: JSON_THROW_ON_ERROR);
    }

    private function query(string $routingKey, string $payload): array
    {
        $queryId = 'query-' . (count($this->pulse->queryResults) + 1);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', $routingKey, $payload, MediaType::APPLICATION_JSON, metadata: ['ecotone_pulse_query_id' => $queryId], replyRoutingKey: 'ecotone.pulse.query_result_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertArrayHasKey($queryId, $this->pulse->queryResults);

        return json_decode($this->pulse->queryResults[$queryId], true, flags: JSON_THROW_ON_ERROR);
    }
}
