<?php

declare(strict_types=1);


namespace Test\Ecotone\Laravel\Application\Execution;

use Ecotone\Laravel\Queue\LaravelQueueMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\Laravel\Fixture\AsynchronousMessageHandler\AsyncCommandHandler;
use Test\Ecotone\Laravel\Fixture\AsynchronousMessageHandler\AsyncEventHandler;
use Test\Ecotone\Laravel\Fixture\AsynchronousMessageHandler\ExampleCommand;
use Test\Ecotone\Laravel\Fixture\AsynchronousMessageHandler\ExampleEvent;

final class LaravelQueueIntegrationTest extends TestCase
{
    public function setUp(): void
    {
        $this->getContainer();

        if (Schema::hasTable('jobs')) {
            Schema::drop('jobs');
        }
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->tinyInteger('attempts')->unsigned();
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
            $table->index(['queue', 'reserved_at']);
        });
    }

    public function test_no_message_in_the_channel()
    {
        $channelName = 'async_channel';

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [AsyncCommandHandler::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create($channelName, 'database'),
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
        $channelName = 'async_channel';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [AsyncCommandHandler::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create($channelName),
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
        $this->assertEquals(MediaType::createApplicationXPHPSerialized(), $receivedMessage[0]['headers'][MessageHeaders::CONTENT_TYPE]);
    }

    public function test_acking_messages()
    {
        $channelName = 'async_channel';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [AsyncCommandHandler::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload);
        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }

    public function test_requeing_message_when_fails()
    {
        $channelName = 'async_channel';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [AsyncCommandHandler::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->sendCommandWithRoutingKey('execute.fail', $messagePayload);
        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));
        $this->assertCount(2, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }

    public function test_sending_via_routing_without_payload()
    {
        $channelName = 'async_channel';

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [AsyncCommandHandler::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->sendCommandWithRoutingKey('execute.noPayload');
        $this->assertCount(0, $messaging->sendQueryWithRouting('consumer.getMessages'));

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }

    public function test_sending_via_routing_with_array_payload()
    {
        $channelName = 'async_channel';
        $payload = ['token' => 'test'];

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [AsyncCommandHandler::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create($channelName),
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
        $channelName = 'async_channel';
        $payload = '{"name":"johny"}';

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [AsyncCommandHandler::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create($channelName),
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
        $channelName = 'async_channel';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [AsyncCommandHandler::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload);
        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());

        $this->assertEquals(
            ExampleCommand::class,
            $messaging->sendQueryWithRouting('consumer.getMessages')[0]['headers'][MessageHeaders::TYPE_ID]
        );
    }

    public function test_sending_and_receiving_events()
    {
        $channelName = 'async_channel';
        $messagePayload = new ExampleEvent(Uuid::uuid4()->toString());

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [AsyncEventHandler::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->publishEvent($messagePayload);
        /** Consumer not yet run */
        $this->assertEquals(
            [],
            $messaging->sendQueryWithRouting('consumer.getEvents')
        );

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertEquals(
            [$messagePayload],
            $messaging->sendQueryWithRouting('consumer.getEvents')
        );

        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertEquals(
            [$messagePayload, $messagePayload],
            $messaging->sendQueryWithRouting('consumer.getEvents')
        );
    }

    public function test_sending_with_delay()
    {
        $channelName = 'async_channel';
        $messagePayload = new ExampleCommand(Uuid::uuid4()->toString());

        $messaging = EcotoneLite::bootstrapFlowTesting(
            [AsyncCommandHandler::class],
            $this->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    LaravelQueueMessageChannelBuilder::create($channelName),
                ])
        );

        $messaging->sendCommandWithRoutingKey('execute.example_command', $messagePayload, metadata: [
            MessageHeaders::DELIVERY_DELAY => 1000,
        ]);
        $messaging->run($channelName, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 2000));
        $this->assertCount(1, $messaging->sendQueryWithRouting('consumer.getMessages'));
    }

    private function getContainer(): ContainerInterface
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
