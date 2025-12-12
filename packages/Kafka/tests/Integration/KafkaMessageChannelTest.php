<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Api\KafkaHeader;
use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\TestConfiguration;
use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Test\LicenceTesting;
use Ecotone\Test\StubLogger;

use function getenv;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;
use Test\Ecotone\Kafka\Fixture\Calendar\Calendar;
use Test\Ecotone\Kafka\Fixture\Calendar\CreateCalendar;
use Test\Ecotone\Kafka\Fixture\Calendar\MeetingHistory;
use Test\Ecotone\Kafka\Fixture\Calendar\ScheduleMeeting;
use Test\Ecotone\Kafka\Fixture\Handler\ExampleCommand;
use Test\Ecotone\Kafka\Fixture\Handler\ExampleEvent;
use Test\Ecotone\Kafka\Fixture\Handler\KafkaAsyncCommandHandler;
use Test\Ecotone\Kafka\Fixture\Handler\KafkaAsyncEventHandler;

/**
 * @internal
 */
/**
 * licence Enterprise
 * @internal
 */
#[RunTestsInSeparateProcesses]
final class KafkaMessageChannelTest extends TestCase
{
    public function test_connecting_to_non_existing_topic()
    {
        $channelName = 'async';

        $messaging = $this->prepareAsyncCommandHandler($channelName);

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));

        $this->assertEquals(
            [],
            $messaging->sendQueryWithRouting('consumer.getMessages')
        );
    }

    public function test_sending_and_receiving_message_from_channel()
    {
        $channelName = 'async';
        $messageId = Uuid::uuid4()->toString();
        $messagePayload = new ExampleCommand($messageId);

        $messaging = $this->prepareAsyncCommandHandler($channelName, $topicName = Uuid::uuid4()->toString());
        $metadata = [
            MessageHeaders::MESSAGE_ID => $messageId,
            MessageHeaders::TIMESTAMP => 123333,
        ];

        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload, metadata: $metadata);
        /** Consumer not yet run */
        $this->assertEquals(
            [],
            $messaging->sendQueryWithRouting('consumer.getMessages')
        );

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));

        $receivedMessage = $messaging->sendQueryWithRouting('consumer.getMessages');
        $this->assertEquals($messagePayload, $receivedMessage[0]['payload']);
        $this->assertEquals($metadata[MessageHeaders::MESSAGE_ID], $receivedMessage[0]['headers'][MessageHeaders::MESSAGE_ID]);
        $this->assertEquals($metadata[MessageHeaders::MESSAGE_ID], $receivedMessage[0]['headers'][KafkaHeader::KAFKA_SOURCE_PARTITION_KEY_HEADER_NAME]);
        $this->assertEquals($metadata[MessageHeaders::TIMESTAMP], $receivedMessage[0]['headers'][MessageHeaders::TIMESTAMP]);
        $this->assertEquals($topicName, $receivedMessage[0]['headers'][KafkaHeader::TOPIC_HEADER_NAME]);
        $this->assertEquals(0, $receivedMessage[0]['headers'][KafkaHeader::PARTITION_HEADER_NAME]);
        $this->assertEquals(0, $receivedMessage[0]['headers'][KafkaHeader::OFFSET_HEADER_NAME]);
        $this->assertEquals($channelName, $receivedMessage[0]['headers'][MessageHeaders::POLLED_CHANNEL_NAME]);
        $this->assertEquals(MediaType::createApplicationXPHPSerialized()->toString(), $receivedMessage[0]['headers'][MessageHeaders::CONTENT_TYPE]);
    }

    public function test_executing_aggregate_instance_by_command_identifier_from_correct_partition()
    {
        $channelName = 'async';
        $calendarId = Uuid::uuid4()->toString();

        $messaging = $this
            ->prepareAsyncCommandHandler($channelName, Uuid::uuid4()->toString())
            ->sendCommand(new CreateCalendar($calendarId));

        $messaging
            ->sendCommand(new ScheduleMeeting($calendarId, Uuid::uuid4()->toString()));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));
        $meetings = $messaging->sendQueryWithRouting('calendar.getMeetings', metadata: ['aggregate.id' => $calendarId]);
        $this->assertEquals($calendarId, $meetings[0]['metadata'][KafkaHeader::KAFKA_SOURCE_PARTITION_KEY_HEADER_NAME]);

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));
        $calendarHistory = $messaging->sendQueryWithRouting('meeting.getHistory');
        $this->assertCount(1, $calendarHistory);
        $this->assertEquals($calendarId, $calendarHistory[0]['metadata'][KafkaHeader::KAFKA_SOURCE_PARTITION_KEY_HEADER_NAME]);
    }

    public function test_forcing_partition_key()
    {
        $channelName = 'async';
        $calendarId = Uuid::uuid4()->toString();

        $messaging = $this
            ->prepareAsyncCommandHandler($channelName, Uuid::uuid4()->toString())
            ->sendCommand(new CreateCalendar($calendarId));

        $messaging
            ->sendCommand(
                new ScheduleMeeting($calendarId, Uuid::uuid4()->toString()),
                metadata: [
                    KafkaHeader::KAFKA_TARGET_PARTITION_KEY_HEADER_NAME => '123',
                ]
            );

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));
        $meetings = $messaging->sendQueryWithRouting('calendar.getMeetings', metadata: ['aggregate.id' => $calendarId]);
        $this->assertEquals('123', $meetings[0]['metadata'][KafkaHeader::KAFKA_SOURCE_PARTITION_KEY_HEADER_NAME]);

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));
        $calendarHistory = $messaging->sendQueryWithRouting('meeting.getHistory');
        $this->assertEquals($calendarId, $calendarHistory[0]['metadata'][KafkaHeader::KAFKA_SOURCE_PARTITION_KEY_HEADER_NAME]);
    }

    public function test_failing_to_consume_due_to_connection_failure()
    {
        $channelName = 'async';

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [KafkaAsyncCommandHandler::class],
            [
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([
                    'wronghost:9092',
                ]), new KafkaAsyncCommandHandler(), 'logger' => $logger = StubLogger::create(),
            ],
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        $channelName,
                        topicName: $uniqueId = Uuid::uuid4()->toString(),
                        messageGroupId: $uniqueId
                    ),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));

        $this->assertEmpty($messaging->sendQueryWithRouting('consumer.getMessages'));
        ;
        $this->assertNotEmpty($logger->getError());
    }

    public function test_acking_messages()
    {
        $channelName = 'async';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = $this->prepareAsyncCommandHandler($channelName);

        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload);
        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));
        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));
        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }

    public function test_requeing_message_when_fails()
    {
        $channelName = 'async';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = $this->prepareAsyncCommandHandler($channelName);

        $messaging->sendCommandWithRoutingKey('execute.fail', $messagePayload, metadata: [
            'failCount' => 1,
        ]);
        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 2000, failAtError: false));
        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 2000, failAtError: false));
        $this->assertCount(2, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 2000, failAtError: false));
        $this->assertCount(2, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }

    public function test_sending_via_routing_without_payload()
    {
        $channelName = 'async';

        $messaging = $this->prepareAsyncCommandHandler($channelName);

        $messaging->sendCommandWithRoutingKey('execute.noPayload');
        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));

        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }

    public function test_sending_via_routing_with_array_payload()
    {
        $channelName = 'async';
        $payload = ['token' => 'test'];

        $messaging = $this->prepareAsyncCommandHandler($channelName);

        $messaging->sendCommandWithRoutingKey('execute.arrayPayload', $payload);
        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));

        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
        $this->assertEquals($payload, $messaging->sendQueryWithRouting('consumer.getMessages')[0]['payload']);
    }

    public function test_keeping_content_type_when_non_object_payload()
    {
        $channelName = 'async';
        $payload = '{"name":"johny"}';

        $messaging = $this->prepareAsyncCommandHandler($channelName);

        $messaging->sendCommandWithRoutingKey('execute.stringPayload', $payload, MediaType::APPLICATION_JSON);

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));

        $headers = $messaging->sendQueryWithRouting('consumer.getMessages')[0]['headers'];
        $this->assertEquals(
            $headers[MessageHeaders::CONTENT_TYPE],
            MediaType::APPLICATION_JSON
        );
    }

    public function test_adding_type_id_header()
    {
        $channelName = 'async';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = $this->prepareAsyncCommandHandler($channelName);

        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload);
        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));

        $this->assertEquals(
            ExampleCommand::class,
            $messaging->sendQueryWithRouting('consumer.getMessages')[0]['headers'][MessageHeaders::TYPE_ID]
        );
    }

    public function test_sending_and_receiving_events()
    {
        $channelName = 'async';
        $messagePayload = new ExampleEvent(Uuid::uuid4()->toString());

        $messaging = $this->prepareAsyncEventHandler($channelName);

        $messaging->publishEvent($messagePayload);
        /** Consumer not yet run */
        $this->assertEquals(
            [],
            $messaging->sendQueryWithRouting('consumer.getEvents')
        );

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));
        $this->assertEquals(
            [$messagePayload],
            $messaging->sendQueryWithRouting('consumer.getEvents')
        );

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 4000));
        $this->assertEquals(
            [$messagePayload, $messagePayload],
            $messaging->sendQueryWithRouting('consumer.getEvents')
        );
    }

    public function prepareAsyncCommandHandler(string $channelName, ?string $topicName = null): \Ecotone\Lite\Test\FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [KafkaAsyncCommandHandler::class, Calendar::class, MeetingHistory::class],
            [
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([
                    getenv('KAFKA_DSN') ?? 'localhost:9094',
                ]), new KafkaAsyncCommandHandler(), new MeetingHistory(),
                //'logger' => new EchoLogger(),
            ],
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        $channelName,
                        topicName: ($topicName = $topicName ?: Uuid::uuid4()->toString()),
                        messageGroupId: $topicName
                    ),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function prepareAsyncEventHandler(string $channelName): \Ecotone\Lite\Test\FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [KafkaAsyncEventHandler::class],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(), new KafkaAsyncEventHandler(),
                //                'logger' => new EchoLogger(),
            ],
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        $channelName,
                        topicName: $uniqueId = Uuid::uuid4()->toString(),
                        messageGroupId: $uniqueId
                    ),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_two_consumers_track_positions_independently(): void
    {
        $channelName = 'kafka_channel';
        $topicName = 'test_topic_two_consumers_' . Uuid::uuid4()->toString();

        $handler1 = new class () {
            private array $consumed = [];

            #[InternalHandler(inputChannelName: 'kafka_channel', endpointId: 'consumer1')]
            public function handle(string $payload): void
            {
                $this->consumed[] = $payload;
            }

            #[QueryHandler('getConsumed1')]
            public function getConsumed(): array
            {
                return $this->consumed;
            }
        };

        $handler2 = new class () {
            private array $consumed = [];

            #[InternalHandler(inputChannelName: 'kafka_channel', endpointId: 'consumer2')]
            public function handle(string $payload): void
            {
                $this->consumed[] = $payload;
            }

            #[QueryHandler('getConsumed2')]
            public function getConsumed(): array
            {
                return $this->consumed;
            }
        };

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [$handler1::class, $handler2::class],
            [
                $handler1,
                $handler2,
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: $channelName,
                        topicName: $topicName,
                        messageGroupId: $messageGroupId = $topicName
                    )
                        ->withCommitInterval(1), // Commit after each message
                    TestConfiguration::createWithDefaults(),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Send 3 messages to the Kafka topic
        $channel = $ecotoneLite->getMessageChannel($channelName);
        $channel->send(MessageBuilder::withPayload('message1')->setHeader(MessageHeaders::CONTENT_TYPE, MediaType::TEXT_PLAIN)->build());
        $channel->send(MessageBuilder::withPayload('message2')->setHeader(MessageHeaders::CONTENT_TYPE, MediaType::TEXT_PLAIN)->build());
        $channel->send(MessageBuilder::withPayload('message3')->setHeader(MessageHeaders::CONTENT_TYPE, MediaType::TEXT_PLAIN)->build());

        // Consumer1 consumes first message
        $ecotoneLite->run('consumer1', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['message1'], $ecotoneLite->sendQueryWithRouting('getConsumed1'));
        $this->assertEquals([], $ecotoneLite->sendQueryWithRouting('getConsumed2'));

        // Consumer2 consumes first two messages
        $ecotoneLite->run('consumer2', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $ecotoneLite->run('consumer2', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['message1'], $ecotoneLite->sendQueryWithRouting('getConsumed1'));
        $this->assertEquals(['message1', 'message2'], $ecotoneLite->sendQueryWithRouting('getConsumed2'));

        // Consumer1 consumes second and third messages
        $ecotoneLite->run('consumer1', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $ecotoneLite->run('consumer1', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1));
        $this->assertEquals(['message1', 'message2', 'message3'], $ecotoneLite->sendQueryWithRouting('getConsumed1'));
        $this->assertEquals(['message1', 'message2'], $ecotoneLite->sendQueryWithRouting('getConsumed2'));

        // Verify positions are tracked independently by querying Kafka committed offsets
        /** @var KafkaAdmin $kafkaAdmin */
        $kafkaAdmin = $ecotoneLite->getServiceFromContainer(KafkaAdmin::class);

        $consumer1 = $kafkaAdmin->getConsumer('consumer1', $channelName);
        $consumer2 = $kafkaAdmin->getConsumer('consumer2', $channelName);

        // Create TopicPartition objects to query committed offsets (partition 0 is default for single partition topics)
        $topicPartition = new \RdKafka\TopicPartition($topicName, 0);

        // Get committed offsets for each consumer
        $consumer1Offsets = $consumer1->getCommittedOffsets([$topicPartition], 10000);
        $consumer2Offsets = $consumer2->getCommittedOffsets([$topicPartition], 10000);

        // Verify each consumer has committed the correct offset
        // Kafka offsets point to the next message to consume, so offset 3 means 3 messages consumed (0, 1, 2)
        $this->assertEquals(3, $consumer1Offsets[0]->getOffset(), 'Consumer1 should have committed offset 3 (consumed messages 0, 1, 2)');
        $this->assertEquals(2, $consumer2Offsets[0]->getOffset(), 'Consumer2 should have committed offset 2 (consumed messages 0, 1)');
    }

    public function test_default_message_group_id(): void
    {
        $channelName = 'kafka_channel';
        $topicName = 'test_topic_two_consumers_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: $channelName,
                    )
                        ->withCommitInterval(1), // Commit after each message
                    TestConfiguration::createWithDefaults(),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Verify positions are tracked independently by querying Kafka committed offsets
        /** @var KafkaAdmin $kafkaAdmin */
        $kafkaAdmin = $ecotoneLite->getServiceFromContainer(KafkaAdmin::class);

        // Verify group IDs are set correctly
        $this->assertEquals(
            $channelName,
            $kafkaAdmin->getConsumerConfiguration($channelName, $channelName)->getGroupId()
        );
        $this->assertEquals(
            $channelName . '_consumer1',
            $kafkaAdmin->getConsumerConfiguration('consumer1', $channelName)->getGroupId()
        );
    }

    public function test_predefined_message_group_id(): void
    {
        $channelName = 'kafka_channel';
        $topicName = 'test_topic_two_consumers_' . Uuid::uuid4()->toString();

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [],
            [
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: $channelName,
                        messageGroupId: $messageGroupId = 'predefined_group_id',
                    )
                        ->withCommitInterval(1), // Commit after each message
                    TestConfiguration::createWithDefaults(),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Verify positions are tracked independently by querying Kafka committed offsets
        /** @var KafkaAdmin $kafkaAdmin */
        $kafkaAdmin = $ecotoneLite->getServiceFromContainer(KafkaAdmin::class);

        // Verify group IDs are set correctly
        $this->assertEquals(
            $messageGroupId,
            $kafkaAdmin->getConsumerConfiguration($channelName, $channelName)->getGroupId()
        );
        $this->assertEquals(
            $messageGroupId . '_consumer1',
            $kafkaAdmin->getConsumerConfiguration('consumer1', $channelName)->getGroupId()
        );
    }

    /**
     * This test verifies that Kafka channels can be used for distributed event publishing,
     * where one service publishes events and multiple consuming services each have their own
     * consumer group to track their position independently.
     */
    public function test_streaming_channel_with_distributed_bus_using_service_map(): void
    {
        $topicName = 'distributed_events_' . Uuid::uuid4()->toString();

        // Publisher service
        $publisher = new class () {
            #[\Ecotone\Modelling\Attribute\CommandHandler('publish.event')]
            public function publish(string $payload, \Ecotone\Modelling\EventBus $eventBus): void
            {
                $eventBus->publish($payload);
            }
        };

        // Consumer 1 in service 1
        $consumer1 = new class () {
            private array $consumed = [];

            #[\Ecotone\Modelling\Attribute\Distributed]
            #[\Ecotone\Modelling\Attribute\EventHandler('distributed.event', endpointId: 'consumer1')]
            public function handle(string $payload): void
            {
                $this->consumed[] = $payload;
            }

            #[QueryHandler('getConsumed1')]
            public function getConsumed(): array
            {
                return $this->consumed;
            }
        };

        // Consumer 2 in service 2
        $consumer2 = new class () {
            private array $consumed = [];

            #[\Ecotone\Modelling\Attribute\Distributed]
            #[\Ecotone\Modelling\Attribute\EventHandler('distributed.event', endpointId: 'consumer2')]
            public function handle(string $payload): void
            {
                $this->consumed[] = $payload;
            }

            #[QueryHandler('getConsumed2')]
            public function getConsumed(): array
            {
                return $this->consumed;
            }
        };

        $channelName = 'distributed_events';

        // Publisher service
        $publisherService = EcotoneLite::bootstrapFlowTesting(
            [$publisher::class],
            [
                $publisher,
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withServiceName('publisher-service')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: $channelName,
                        topicName: $topicName,
                    )
                        ->withCommitInterval(1),
                    \Ecotone\Modelling\Api\Distribution\DistributedServiceMap::initialize()
                        ->withServiceMapping(serviceName: 'distributed_events_channel', channelName: $channelName),
                    TestConfiguration::createWithDefaults(),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Consumer service 1
        $consumerService1 = EcotoneLite::bootstrapFlowTesting(
            [$consumer1::class],
            [
                $consumer1,
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withServiceName('service1')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: $channelName,
                        topicName: $topicName,
                        messageGroupId: 'consumer1' // Each consumer needs its own consumer group
                    )
                        ->withCommitInterval(1),
                    TestConfiguration::createWithDefaults(),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Consumer service 2
        $consumerService2 = EcotoneLite::bootstrapFlowTesting(
            [$consumer2::class],
            [
                $consumer2,
                KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withServiceName('service2')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::KAFKA_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create(
                        channelName: $channelName,
                        topicName: $topicName,
                        messageGroupId: 'consumer2' // Each consumer needs its own consumer group
                    )
                        ->withCommitInterval(1),
                    TestConfiguration::createWithDefaults(),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Publish events
        $publisherService->getDistributedBus()->publishEvent('distributed.event', 'event1');
        $publisherService->getDistributedBus()->publishEvent('distributed.event', 'event2');
        $publisherService->getDistributedBus()->publishEvent('distributed.event', 'event3');

        // Both consumers should receive all events independently
        // Using amountOfMessagesToHandle and maxExecutionTimeInMilliseconds for Kafka consumer group coordination
        $consumerService1->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 10, maxExecutionTimeInMilliseconds: 4000));
        $consumerService2->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 10, maxExecutionTimeInMilliseconds: 4000));

        $this->assertEquals(['event1', 'event2', 'event3'], $consumerService1->sendQueryWithRouting('getConsumed1'));
        $this->assertEquals(['event1', 'event2', 'event3'], $consumerService2->sendQueryWithRouting('getConsumed2'));
    }
}
