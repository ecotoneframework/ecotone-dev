<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\ExecutionPollingMetadata;
use Ecotone\Api\ServiceConfiguration;
use Ecotone\Api\SimpleMessageChannelBuilder;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\TimeSpan;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class DelayedMessageReleaseTest extends TestCase
{
    public function test_delayed_message_is_not_processed_before_it_is_released(): void
    {
        $handler = new DelayedMessageHandler();
        $ecotone = $this->bootstrap($handler);

        $ecotone->sendCommandWithRoutingKey(DelayedMessageHandler::ROUTING_KEY, 'a', metadata: [
            MessageHeaders::DELIVERY_DELAY => 10_000,
        ]);

        $ecotone->run(DelayedMessageHandler::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false), TimeSpan::withSeconds(5));

        $this->assertSame([], $handler->processed);
    }

    public function test_releasing_processes_messages_that_have_reached_their_delay(): void
    {
        $handler = new DelayedMessageHandler();
        $ecotone = $this->bootstrap($handler);

        $ecotone->sendCommandWithRoutingKey(DelayedMessageHandler::ROUTING_KEY, 'a', metadata: [
            MessageHeaders::DELIVERY_DELAY => 10_000,
        ]);

        $ecotone->run(DelayedMessageHandler::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false), TimeSpan::withSeconds(10));

        $this->assertSame(['a'], $handler->processed);
    }

    public function test_releasing_processes_multiple_delayed_messages_in_ascending_delay_order(): void
    {
        $handler = new DelayedMessageHandler();
        $ecotone = $this->bootstrap($handler);

        $ecotone->sendCommandWithRoutingKey(DelayedMessageHandler::ROUTING_KEY, 'first', metadata: [MessageHeaders::DELIVERY_DELAY => 3000]);
        $ecotone->sendCommandWithRoutingKey(DelayedMessageHandler::ROUTING_KEY, 'second', metadata: [MessageHeaders::DELIVERY_DELAY => 2000]);
        $ecotone->sendCommandWithRoutingKey(DelayedMessageHandler::ROUTING_KEY, 'third', metadata: [MessageHeaders::DELIVERY_DELAY => 1000]);

        $ecotone->run(DelayedMessageHandler::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false), TimeSpan::withSeconds(1));
        $this->assertSame(['third'], $handler->processed);

        $ecotone->run(DelayedMessageHandler::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false), TimeSpan::withSeconds(2));
        $this->assertSame(['third', 'second'], $handler->processed);

        $ecotone->run(DelayedMessageHandler::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false), TimeSpan::withSeconds(3));
        $this->assertSame(['third', 'second', 'first'], $handler->processed);
    }

    private function bootstrap(DelayedMessageHandler $handler)
    {
        return EcotoneLite::bootstrapFlowTesting(
            [DelayedMessageHandler::class],
            [$handler],
            ServiceConfiguration::createWithDefaults()->withExtensionObjects([
                SimpleMessageChannelBuilder::createQueueChannel(DelayedMessageHandler::CHANNEL, delayable: true),
            ]),
        );
    }
}

/**
 * licence Apache-2.0
 *
 * @internal
 */
final class DelayedMessageHandler
{
    public const CHANNEL = 'delayedMessage.channel';
    public const ROUTING_KEY = 'delayedMessage.handle';

    public array $processed = [];

    #[Asynchronous(self::CHANNEL)]
    #[CommandHandler(self::ROUTING_KEY, 'delayedMessageHandler')]
    public function handle(string $payload): void
    {
        $this->processed[] = $payload;
    }
}
