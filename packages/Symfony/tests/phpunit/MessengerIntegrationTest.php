<?php

declare(strict_types=1);

namespace Test;

use Doctrine\DBAL\Connection;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannelBuilder;
use Fixture\MessengerConsumer\ExampleCommand;
use Fixture\MessengerConsumer\MessengerAsyncMessageHandler;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 */
final class MessengerIntegrationTest extends WebTestCase
{
    public function setUp(): void
    {
        /** @var Connection $connection */
        $connection = self::bootKernel()->getContainer()->get('Doctrine\DBAL\Connection-public');

        /** delete from messenger_message table if exists using schema manager */

        $schemaManager = $connection->getSchemaManager();
        if ($schemaManager->createSchema()->hasTable('messenger_messages')) {
            $connection->executeQuery('DELETE FROM messenger_messages');
        }
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

    public function test_sending_via_routing_without_payload()
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

        $messaging->sendCommandWithRoutingKey('execute.noPayload');
        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }

    public function test_sending_via_routing_with_array_payload()
    {
        $channelName = 'messenger_async';
        $payload = ['token' => 'test'];

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncMessageHandler::class],
            $this->bootKernel()->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    SymfonyMessengerMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->sendCommandWithRoutingKey('execute.arrayPayload', $payload);
        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
        $this->assertEquals($payload, $messaging->sendQueryWithRouting('consumer.getMessages')[0]['payload']);
    }

    public function test_keeping_content_type_when_non_object_payload()
    {
        $channelName = 'messenger_async';
        $payload = '{"name":"johny"}';

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [MessengerAsyncMessageHandler::class],
            $this->bootKernel()->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    SymfonyMessengerMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->sendCommandWithRoutingKey('execute.stringPayload', $payload, MediaType::APPLICATION_JSON);

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());

        $headers = $messaging->sendQueryWithRouting('consumer.getMessages')[0]['headers'];
        $this->assertEquals(
            $headers[MessageHeaders::CONTENT_TYPE],
            MediaType::APPLICATION_JSON
        );
    }

    public function test_adding_type_id_header()
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
        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals(
            ExampleCommand::class,
            $messaging->sendQueryWithRouting('consumer.getMessages')[0]['headers'][MessageHeaders::TYPE_ID]
        );
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
            MessageHeaders::DELIVERY_DELAY => 1000,
        ]);
        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 2000));
        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }
}
