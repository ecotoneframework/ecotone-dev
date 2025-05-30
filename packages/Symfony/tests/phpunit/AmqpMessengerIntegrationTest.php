<?php

declare(strict_types=1);

namespace Test;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\SymfonyBundle\Messenger\SymfonyMessengerMessageChannelBuilder;
use Fixture\MessengerConsumer\AmqpExampleCommand;
use Fixture\MessengerConsumer\AmqpMessengerAsyncCommandHandler;
use PHPUnit\Framework\Attributes\After;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class AmqpMessengerIntegrationTest extends WebTestCase
{
    private string $channelName = 'amqp_async';
    private FlowTestSupport $messaging;

    protected function tearDown(): void
    {
        restore_exception_handler();
    }

    protected function setUp(): void
    {
        $this->messaging = EcotoneLite::bootstrapFlowTesting(
            [AmqpMessengerAsyncCommandHandler::class],
            self::bootKernel()->getContainer(),
            ServiceConfiguration::createWithAsynchronicityOnly()
                ->withExtensionObjects([
                    SymfonyMessengerMessageChannelBuilder::create($this->channelName),
                ])
        );

    }

    public function test_empty_queue(): void
    {
        $this->messaging->run($this->channelName, ExecutionPollingMetadata::createWithTestingSetup());
        $this->assertEquals([], $this->messaging->sendQueryWithRouting('amqp.consumer.getCommands'));
    }

    public function test_single_command(): void
    {
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            $this->markTestSkipped('TypeError: Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection::nack(): Return value must be of type bool, null returned');
        }

        $this->messaging->sendCommandWithRoutingKey('amqp.test.example_command', new AmqpExampleCommand('single_1'));
        $this->assertCount(0, $this->messaging->sendQueryWithRouting('amqp.consumer.getCommands'));

        $this->messaging->run($this->channelName, ExecutionPollingMetadata::createWithTestingSetup());
        $commands = $this->messaging->sendQueryWithRouting('amqp.consumer.getCommands');
        $this->assertCount(1, $commands);
        $this->assertEquals('single_1', $commands[0]['id']);
    }

    public function test_multiple_commands(): void
    {
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            $this->markTestSkipped('TypeError: Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection::nack(): Return value must be of type bool, null returned');
        }

        $this->messaging->sendCommandWithRoutingKey('amqp.test.example_command', new AmqpExampleCommand('multi_1'));
        $this->messaging->sendCommandWithRoutingKey('amqp.test.example_command', new AmqpExampleCommand('multi_2'));
        /** Consumer not yet run */
        $this->assertCount(0, $this->messaging->sendQueryWithRouting('amqp.consumer.getCommands'));

        $this->messaging->run($this->channelName, ExecutionPollingMetadata::createWithTestingSetup());
        $commands = $this->messaging->sendQueryWithRouting('amqp.consumer.getCommands');
        $this->assertCount(1, $commands);
        $this->assertEquals('multi_1', $commands[0]['id']);

        $this->messaging->run($this->channelName, ExecutionPollingMetadata::createWithTestingSetup());
        $commands = $this->messaging->sendQueryWithRouting('amqp.consumer.getCommands');
        $this->assertCount(2, $commands);
        $this->assertEquals('multi_1', $commands[0]['id']);
        $this->assertEquals('multi_2', $commands[1]['id']);
    }
}
