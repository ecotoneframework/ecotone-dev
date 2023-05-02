<?php

declare(strict_types=1);

namespace Test;

use Doctrine\DBAL\Connection;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\SymfonyBundle\Messenger\MetadataStamp;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannelBuilder;
use Fixture\MessengerConsumer\ExampleCommand;
use Fixture\MessengerConsumer\MessagingConfiguration;
use Fixture\MessengerConsumer\MessengerAsyncMessageHandler;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemoryTransport;
use Symfony\Bundle\FrameworkBundle\Console\Application;

final class MessengerIntegrationTest extends WebTestCase
{
    public function setUp(): void
    {
//        self::bootKernel()->getContainer()->get('Doctrine\DBAL\Connection-public')->executeQuery('DELETE FROM messenger_messages');
    }

    public function test_no_message_in_the_channel()
    {
        $channelName = 'messenger_async';

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncMessageHandler::class],
            $this->bootKernel()->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    SymfonyMessengerMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals(
            [],
            $messaging->sendQueryWithRouting('consumer.getMessages')
        );
    }

    public function test_sending_and_receiving_message_from_channel()
    {
        $channelName = 'messenger_async';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncMessageHandler::class],
            $this->bootKernel()->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    SymfonyMessengerMessageChannelBuilder::create($channelName),
                ])
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

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());

        $receivedMessage = $messaging->sendQueryWithRouting('consumer.getMessages');
        $this->assertEquals($messagePayload, $receivedMessage[0]['payload']);
        $this->assertEquals($metadata[MessageHeaders::MESSAGE_ID], $receivedMessage[0]['headers'][MessageHeaders::MESSAGE_ID]);
        $this->assertEquals($metadata[MessageHeaders::TIMESTAMP], $receivedMessage[0]['headers'][MessageHeaders::TIMESTAMP]);
        $this->assertEquals($channelName, $receivedMessage[0]['headers'][MessageHeaders::POLLED_CHANNEL_NAME]);
        $this->assertEquals($channelName, $receivedMessage[0]['headers'][MessageHeaders::CONSUMER_ENDPOINT_ID]);
        $this->assertEquals(MediaType::createApplicationXPHPWithTypeParameter($messagePayload::class), $receivedMessage[0]['headers'][MessageHeaders::CONTENT_TYPE]);
    }

    public function test_acking_messages()
    {
        $channelName = 'messenger_async';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncMessageHandler::class],
            $this->bootKernel()->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    SymfonyMessengerMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload);
        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }

    /** Rejecting instead of requeuing due to lack of support */
    public function test_rejecting_message_when_fails()
    {
        $channelName = 'messenger_async';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncMessageHandler::class],
            $this->bootKernel()->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    SymfonyMessengerMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->sendCommandWithRoutingKey('execute.fail', $messagePayload);
        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }

    public function test_sending_with_delay()
    {
        $channelName = 'messenger_async';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncMessageHandler::class],
            $this->bootKernel()->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    SymfonyMessengerMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload, metadata: [
            MessageHeaders::DELIVERY_DELAY => 1000
        ]);
        $messaging->run($channelName, ExecutionPollingMetadata::createWithDefaults()->withExecutionTimeLimitInMilliseconds(100)->withStopOnError(true));
        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));
        sleep(2);
        $messaging->run($channelName, ExecutionPollingMetadata::createWithDefaults()->withExecutionTimeLimitInMilliseconds(100)->withStopOnError(true));
        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }
}