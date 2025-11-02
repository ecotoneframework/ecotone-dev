<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Api\KafkaHeader;
use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Messaging\MessageHeaders;
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
                        groupId: $uniqueId
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
                        groupId: $topicName
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
                        groupId: $uniqueId
                    ),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
