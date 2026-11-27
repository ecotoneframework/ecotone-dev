<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Pulse;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\PartitionDeadLetter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class PulseHeldPartitionsTest extends DbalMessagingTestCase
{
    private object $orders;
    private object $pulse;
    private FlowTestSupport $orderService;
    private FlowTestSupport $pulseService;

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('implementations')]
    public function test_the_snapshot_lists_held_partition_keys_with_their_messages_in_the_order_they_failed(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->place('broken', orderId: '1', messageId: 'm-1');
        $this->place('coffee', orderId: '1', messageId: 'm-2');
        $this->place('broken', orderId: '2', messageId: 'm-3');
        $this->consumeOrders();

        self::assertSame(
            [
                ['trackingName' => 'pulsePartition.place.endpoint', 'partitionKey' => '1', 'waitingMessages' => 2, 'oldestMessageId' => 'm-1', 'messageIds' => ['m-1', 'm-2']],
                ['trackingName' => 'pulsePartition.place.endpoint', 'partitionKey' => '2', 'waitingMessages' => 1, 'oldestMessageId' => 'm-3', 'messageIds' => ['m-3']],
            ],
            $this->ask('ecotone.pulse.snapshot', '{}')['deadLetterPartitions']['partitions'],
        );
    }

    #[DataProvider('implementations')]
    public function test_replaying_the_oldest_message_of_a_held_partition_key_from_pulse_handles_the_rest_in_order(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->place('broken', orderId: '1', messageId: 'm-1');
        $this->place('coffee', orderId: '1', messageId: 'm-2');
        $this->consumeOrders();

        $this->orders->broken = [];
        $this->ask('ecotone.pulse.dead_letter.replay', '{"messageIds": ["m-1"]}');
        $this->consumeOrders();

        self::assertSame(['broken', 'coffee'], $this->orders->placed);
        self::assertSame([], $this->ask('ecotone.pulse.snapshot', '{}')['deadLetterPartitions']['partitions']);
    }

    #[DataProvider('implementations')]
    public function test_replaying_by_a_filter_from_pulse_starts_every_held_partition_key_with_its_oldest_message(string $implementation): void
    {
        $this->bootstrap($implementation);
        $this->place('broken', orderId: '1');
        $this->place('coffee', orderId: '1');
        $this->place('broken', orderId: '2');
        $this->place('tea', orderId: '2');
        $this->consumeOrders();

        $this->orders->broken = [];
        $reply = $this->ask('ecotone.pulse.dead_letter.replay', '{"filter": {"messageName": "pulsePartition.place"}}');
        $this->consumeOrders();

        self::assertSame(2, $reply['deadLetterAction']['affected']);
        self::assertEqualsCanonicalizing(['broken', 'coffee', 'broken', 'tea'], $this->orders->placed);
        self::assertSame(0, $this->orderService->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_a_dead_letter_table_created_by_ecotone_1x_is_reported_on_the_snapshot_naming_how_to_add_the_partition_columns(): void
    {
        $table = new Table('ecotone_error_messages');
        $table->addColumn('message_id', Types::STRING, ['length' => 255]);
        $table->addColumn('failed_at', Types::DATETIME_MUTABLE);
        $table->addColumn('payload', Types::TEXT);
        $table->addColumn('headers', Types::TEXT);
        $table->setPrimaryKey(['message_id']);
        $this->getConnectionFactory()->createContext()->getDbalConnection()->createSchemaManager()->createTable($table);
        $this->bootstrap('dbal');

        $snapshot = $this->ask('ecotone.pulse.snapshot', '{}');

        self::assertStringStartsWith("The 'ecotone_error_messages' table has no 'partition_tracking_name', 'partition_key' and 'partition_position' columns", $snapshot['sectionErrors']['deadLetterPartitions']);
        self::assertStringContainsString('ALTER TABLE ecotone_error_messages ADD COLUMN partition_tracking_name VARCHAR(255) DEFAULT NULL', $snapshot['sectionErrors']['deadLetterPartitions']);
    }

    private function place(string $item, string $orderId, ?string $messageId = null): void
    {
        $this->orderService->sendCommandWithRouting(
            'pulsePartition.place',
            $item,
            metadata: ['orderId' => $orderId] + ($messageId === null ? [] : [MessageHeaders::MESSAGE_ID => $messageId]),
        );
    }

    private function consumeOrders(): void
    {
        $this->orderService->run('pulsePartition.orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
    }

    private function ask(string $routingKey, string $payload): array
    {
        $received = count($this->pulse->snapshots);
        $this->pulseService->getDistributedBus()->sendMessage('order_service', $routingKey, $payload, MediaType::APPLICATION_JSON, replyRoutingKey: 'ecotone.pulse.snapshot_received');
        $this->orderService->run('order_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $this->pulseService->run('pulse_channel', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertCount($received + 1, $this->pulse->snapshots);

        return json_decode($this->pulse->snapshots[$received], true, flags: JSON_THROW_ON_ERROR);
    }

    private function bootstrap(string $implementation): void
    {
        $this->orders = new class () {
            public array $placed = [];
            public array $broken = ['broken'];

            #[PartitionDeadLetter(partitionHeaderName: 'orderId', trackingName: 'pulsePartition.place.endpoint')]
            #[Asynchronous('pulsePartition.orders')]
            #[CommandHandler('pulsePartition.place', endpointId: 'pulsePartition.place.endpoint')]
            public function place(string $item): void
            {
                if (in_array($item, $this->broken, true)) {
                    throw new RuntimeException("cannot place {$item}");
                }

                $this->placed[] = $item;
            }
        };
        $this->pulse = new class () {
            public array $snapshots = [];

            #[InternalHandler('ecotone.pulse.snapshot_received')]
            public function received(string $snapshot): void
            {
                $this->snapshots[] = $snapshot;
            }
        };
        $channels = [SimpleMessageChannelBuilder::createQueueChannel('order_channel'), SimpleMessageChannelBuilder::createQueueChannel('pulse_channel')];
        $this->orderService = $this->bootstrapFlowTesting(
            classesToResolve: [$this->orders::class],
            containerOrAvailableServices: [$this->orders, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ...$channels,
                DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ecotone_pulse', channelName: 'pulse_channel'),
                ServiceConfiguration::createWithDefaults()->withServiceName('order_service')->withDefaultErrorChannel('dbal_dead_letter'),
                PulseConfiguration::createWithDefaults('ecotone_pulse'),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true, inMemory: $implementation === 'in-memory'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $this->orderService->setupDatabaseTables();
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
}
