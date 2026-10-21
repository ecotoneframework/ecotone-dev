<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Amqp\AmqpConnectionReference;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class RequiredExistingDestinationTest extends AmqpMessagingTestCase
{
    private const CHANNEL = 'required_orders';

    public function test_sending_to_a_missing_queue_is_refused_naming_the_channel_and_the_actual_queue(): void
    {
        $channel = self::CHANNEL;
        $queue = 'renamed_' . Uuid::v7()->toRfc4122();
        $ecotone = $this->bootstrap($this->recorder(), $channel, $queue, true);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf(
            "Channel '%s' requires its destination to exist, but queue '%s' does not exist on AMQP connection '%s', so nothing was sent. Run the consumer of this channel (ecotone:run %s) or declare the queue yourself, or fix the queue name (AmqpBackedMessageChannelBuilder::create('%s', queueName: ...)). To let this channel create the queue on send, remove withRequiredExistingDestination().",
            $channel,
            $queue,
            AmqpConnectionReference::DEFAULT,
            $channel,
            $channel,
        ));

        $ecotone->getCommandBus()->sendWithRouting('place_order', 'order-1');
    }

    public function test_the_refused_send_does_not_create_the_queue_so_it_keeps_being_refused(): void
    {
        $channel = self::CHANNEL;
        $queue = 'q_' . Uuid::v7()->toRfc4122();
        $ecotone = $this->bootstrap($this->recorder(), $channel, $queue, true);

        foreach ([1, 2] as $attempt) {
            try {
                $ecotone->getCommandBus()->sendWithRouting('place_order', 'order-' . $attempt);
                self::fail('Send to a missing queue should be refused');
            } catch (ConfigurationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_sending_works_once_the_consumer_side_has_declared_the_queue(): void
    {
        $channel = self::CHANNEL;
        $queue = 'q_' . Uuid::v7()->toRfc4122();
        $sender = $this->bootstrap($this->recorder(), $channel, $queue, true);
        $consumer = $this->bootstrap($this->recorder(), $channel, $queue, false);

        $consumer->run($channel, ExecutionPollingMetadata::createWithTestingSetup());
        $sender->getCommandBus()->sendWithRouting('place_order', 'order-1');
        $consumer->run($channel, ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['order-1'], $consumer->getQueryBus()->sendWithRouting('placed_orders'));
    }

    public function test_the_channel_creates_its_queue_on_send_by_default(): void
    {
        $channel = self::CHANNEL;
        $queue = 'q_' . Uuid::v7()->toRfc4122();
        $ecotone = $this->bootstrap($this->recorder(), $channel, $queue, false);

        $ecotone->run($channel, ExecutionPollingMetadata::createWithTestingSetup());
        $ecotone->getCommandBus()->sendWithRouting('place_order', 'order-1');
        $ecotone->run($channel, ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['order-1'], $ecotone->getQueryBus()->sendWithRouting('placed_orders'));
    }

    private function recorder(): object
    {
        return new class () {
            public array $orders = [];
        };
    }

    private function bootstrap(object $recorder, string $channel, string $queue, bool $requireExisting): FlowTestSupport
    {
        $builder = AmqpBackedMessageChannelBuilder::create(self::CHANNEL, queueName: $queue);
        if ($requireExisting) {
            $builder = $builder->withRequiredExistingDestination();
        }

        $handler = new class ($recorder) {
            public function __construct(private object $recorder)
            {
            }

            #[Asynchronous('required_orders')]
            #[CommandHandler('place_order', endpointId: 'place_order_handler')]
            public function place(string $orderId): void
            {
                $this->recorder->orders[] = $orderId;
            }

            #[QueryHandler('placed_orders')]
            public function placed(): array
            {
                return $this->recorder->orders;
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler::class => $handler, ...$this->getConnectionFactoryReferences()],
            extensionObjects: [$builder],
            enabledModules: [ModulePackageList::AMQP_PACKAGE],
        );
    }
}
