<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Kafka\Channel\KafkaMessageChannelBuilder;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Test\LicenceTesting;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Test\Ecotone\Kafka\Fixture\Handler\ExampleCommand;
use Test\Ecotone\Kafka\Fixture\Handler\MessengerAsyncCommandHandler;

/**
 * @internal
 */
/**
 * licence Enterprise
 */
final class KafkaMessageChannelTest extends WebTestCase
{
    public function test_no_message_in_the_channel()
    {
        $channelName = 'async';

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncCommandHandler::class],
            [
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([
                    \getenv('KAFKA_DSN') ?? 'localhost:9092'
                ]), new MessengerAsyncCommandHandler()
            ],
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create($channelName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(
            // waiting for initial repartitioning
            maxExecutionTimeInMilliseconds: 15000
        ));

        $this->assertEquals(
            [],
            $messaging->sendQueryWithRouting('consumer.getMessages')
        );
    }

    public function test_sending_and_receiving_message_from_channel()
    {
        $channelName = 'async';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncCommandHandler::class],
            [
                KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults([
                    \getenv('KAFKA_DSN') ?? 'localhost:9092'
                ]), new MessengerAsyncCommandHandler()
            ],
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::KAFKA_PACKAGE]))
                ->withExtensionObjects([
                    KafkaMessageChannelBuilder::create($channelName),
                ]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $metadata = [
            MessageHeaders::MESSAGE_ID => Uuid::uuid4()->toString(),
            MessageHeaders::TIMESTAMP => 123333,
        ];

        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload, metadata: $metadata);
        /** Consumer not yet run */
        $this->assertEquals(
            [],
            $messaging->sendQueryWithRouting('consumer.getMessages')
        );

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(
            // waiting for initial repartitioning
            maxExecutionTimeInMilliseconds: 15000
        ));

        $receivedMessage = $messaging->sendQueryWithRouting('consumer.getMessages');
        $this->assertEquals($messagePayload, $receivedMessage[0]['payload']);
        $this->assertEquals($metadata[MessageHeaders::MESSAGE_ID], $receivedMessage[0]['headers'][MessageHeaders::MESSAGE_ID]);
        $this->assertEquals($metadata[MessageHeaders::TIMESTAMP], $receivedMessage[0]['headers'][MessageHeaders::TIMESTAMP]);
        $this->assertEquals($channelName, $receivedMessage[0]['headers'][MessageHeaders::POLLED_CHANNEL_NAME]);
        $this->assertEquals(MediaType::createApplicationXPHPWithTypeParameter($messagePayload::class), $receivedMessage[0]['headers'][MessageHeaders::CONTENT_TYPE]);
    }

//    public function test_acking_messages()
//    {
//        $channelName = 'async';
//        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());
//
//        $messaging = EcotoneLite::bootstrapFlowTesting(
//            [MessengerAsyncCommandHandler::class],
//            $this->bootKernel()->getContainer(),
//            ServiceConfiguration::createWithAsynchronicityOnly()
//                ->withExtensionObjects([
//                    SymfonyMessengerMessageChannelBuilder::create($channelName),
//                ])
//        );
//
//        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload);
//        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));
//
//        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
//        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
//
//        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
//    }
//
//    public function test_requeing_message_when_fails()
//    {
//        $channelName = 'async';
//        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());
//
//        $messaging = EcotoneLite::bootstrapFlowTesting(
//            [MessengerAsyncCommandHandler::class],
//            $this->bootKernel()->getContainer(),
//            ServiceConfiguration::createWithAsynchronicityOnly()
//                ->withExtensionObjects([
//                    SymfonyMessengerMessageChannelBuilder::create($channelName),
//                ])
//        );
//
//        $messaging->sendCommandWithRoutingKey('execute.fail', $messagePayload);
//        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
//        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
//
//        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
//        $this->assertCount(2, $messaging->sendQueryWithRouting('consumer.getMessages'));
//    }
//
//    public function test_sending_via_routing_without_payload()
//    {
//        $channelName = 'async';
//
//        $messaging = EcotoneLite::bootstrapFlowTesting(
//            [MessengerAsyncCommandHandler::class],
//            $this->bootKernel()->getContainer(),
//            ServiceConfiguration::createWithAsynchronicityOnly()
//                ->withExtensionObjects([
//                    SymfonyMessengerMessageChannelBuilder::create($channelName),
//                ])
//        );
//
//        $messaging->sendCommandWithRoutingKey('execute.noPayload');
//        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));
//
//        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
//
//        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
//    }
//
//    public function test_sending_via_routing_with_array_payload()
//    {
//        $channelName = 'async';
//        $payload = ['token' => 'test'];
//
//        $messaging = EcotoneLite::bootstrapFlowTesting(
//            [MessengerAsyncCommandHandler::class],
//            $this->bootKernel()->getContainer(),
//            ServiceConfiguration::createWithAsynchronicityOnly()
//                ->withExtensionObjects([
//                    SymfonyMessengerMessageChannelBuilder::create($channelName),
//                ])
//        );
//
//        $messaging->sendCommandWithRoutingKey('execute.arrayPayload', $payload);
//        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));
//
//        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
//
//        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
//        $this->assertEquals($payload, $messaging->sendQueryWithRouting('consumer.getMessages')[0]['payload']);
//    }
//
//    public function test_keeping_content_type_when_non_object_payload()
//    {
//        $channelName = 'async';
//        $payload = '{"name":"johny"}';
//
//        $messaging = EcotoneLite::bootstrapFlowTesting(
//            [MessengerAsyncCommandHandler::class],
//            $this->bootKernel()->getContainer(),
//            ServiceConfiguration::createWithAsynchronicityOnly()
//                ->withExtensionObjects([
//                    SymfonyMessengerMessageChannelBuilder::create($channelName),
//                ])
//        );
//
//        $messaging->sendCommandWithRoutingKey('execute.stringPayload', $payload, MediaType::APPLICATION_JSON);
//
//        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
//
//        $headers = $messaging->sendQueryWithRouting('consumer.getMessages')[0]['headers'];
//        $this->assertEquals(
//            $headers[MessageHeaders::CONTENT_TYPE],
//            MediaType::APPLICATION_JSON
//        );
//    }
//
//    public function test_adding_type_id_header()
//    {
//        $channelName = 'async';
//        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());
//
//        $messaging = EcotoneLite::bootstrapFlowTesting(
//            [MessengerAsyncCommandHandler::class],
//            $this->bootKernel()->getContainer(),
//            ServiceConfiguration::createWithAsynchronicityOnly()
//                ->withExtensionObjects([
//                    SymfonyMessengerMessageChannelBuilder::create($channelName),
//                ])
//        );
//
//        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload);
//        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
//
//        $this->assertEquals(
//            ExampleCommand::class,
//            $messaging->sendQueryWithRouting('consumer.getMessages')[0]['headers'][MessageHeaders::TYPE_ID]
//        );
//    }
//
//    public function test_sending_and_receiving_events()
//    {
//        $channelName = 'async';
//        $messagePayload = new ExampleEvent(Uuid::uuid4()->toString());
//
//        $messaging = EcotoneLite::bootstrapFlowTesting(
//            [MessengerAsyncEventHandler::class],
//            $this->bootKernel()->getContainer(),
//            ServiceConfiguration::createWithAsynchronicityOnly()
//                ->withExtensionObjects([
//                    SymfonyMessengerMessageChannelBuilder::create($channelName),
//                ])
//        );
//
//        $messaging->publishEvent($messagePayload);
//        /** Consumer not yet run */
//        $this->assertEquals(
//            [],
//            $messaging->sendQueryWithRouting('consumer.getEvents')
//        );
//
//        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
//        $this->assertEquals(
//            [$messagePayload],
//            $messaging->sendQueryWithRouting('consumer.getEvents')
//        );
//
//        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
//        $this->assertEquals(
//            [$messagePayload, $messagePayload],
//            $messaging->sendQueryWithRouting('consumer.getEvents')
//        );
//    }
//
//    public function test_sending_with_delay()
//    {
//        $channelName = 'async';
//        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());
//
//        $messaging = EcotoneLite::bootstrapFlowTesting(
//            [MessengerAsyncCommandHandler::class],
//            $this->bootKernel()->getContainer(),
//            ServiceConfiguration::createWithAsynchronicityOnly()
//                ->withExtensionObjects([
//                    SymfonyMessengerMessageChannelBuilder::create($channelName),
//                ])
//        );
//
//        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload, metadata: [
//            MessageHeaders::DELIVERY_DELAY => 1000,
//        ]);
//        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 2000));
//        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
//    }
}