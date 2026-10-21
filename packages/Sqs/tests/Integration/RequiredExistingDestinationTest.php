<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\DestinationDoesNotExistException;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Api\Sqs\SqsConnectionFactory;
use Ecotone\Api\Sqs\SqsConnectionReference;
use Exception;
use Test\Ecotone\Sqs\ConnectionTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class RequiredExistingDestinationTest extends ConnectionTestCase
{
    private const CHANNEL = 'required_orders';

    protected function setUp(): void
    {
        parent::setUp();
        $context = self::getConnection()->createContext();
        try {
            $context->deleteQueue($context->createQueue(self::CHANNEL));
        } catch (Exception) {
        }
    }

    public function test_sending_to_a_missing_queue_is_refused_naming_the_channel_and_the_queue(): void
    {
        $ecotone = $this->bootstrap(true);

        $this->expectException(DestinationDoesNotExistException::class);
        $this->expectExceptionMessage(sprintf(
            "Channel '%s' requires its destination to exist, but queue '%s' does not exist on SQS connection '%s', so nothing was sent. Run the consumer of this channel (ecotone:run %s) or create the queue yourself, or fix the queue name (SqsBackedMessageChannelBuilder::create('%s')). To let this channel create the queue on send, remove withRequiredExistingDestination().",
            self::CHANNEL,
            self::CHANNEL,
            SqsConnectionReference::DEFAULT,
            self::CHANNEL,
            self::CHANNEL,
        ));

        $ecotone->getCommandBus()->sendWithRouting('place_order', 'order-1');
    }

    public function test_the_refused_send_does_not_create_the_queue_so_it_keeps_being_refused(): void
    {
        $ecotone = $this->bootstrap(true);

        foreach ([1, 2] as $attempt) {
            try {
                $ecotone->getCommandBus()->sendWithRouting('place_order', 'order-' . $attempt);
                self::fail('Send to a missing queue should be refused');
            } catch (DestinationDoesNotExistException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_sending_works_once_the_consumer_side_has_declared_the_queue(): void
    {
        $sender = $this->bootstrap(true);
        $consumer = $this->bootstrap(false);

        $consumer->run(self::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup());
        $sender->getCommandBus()->sendWithRouting('place_order', 'order-1');
        $consumer->run(self::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['order-1'], $consumer->getQueryBus()->sendWithRouting('placed_orders'));
    }

    public function test_a_queue_deleted_after_a_successful_send_is_refused_on_the_next_send(): void
    {
        $sender = $this->bootstrap(true);
        $consumer = $this->bootstrap(false);
        $consumer->run(self::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup());
        $sender->getCommandBus()->sendWithRouting('place_order', 'order-1');

        $context = self::getConnection()->createContext();
        $context->deleteQueue($context->createQueue(self::CHANNEL));

        $this->expectException(DestinationDoesNotExistException::class);
        $sender->getCommandBus()->sendWithRouting('place_order', 'order-2');
    }

    public function test_the_channel_creates_its_queue_on_send_by_default(): void
    {
        $ecotone = $this->bootstrap(false);

        $ecotone->getCommandBus()->sendWithRouting('place_order', 'order-1');
        $ecotone->run(self::CHANNEL, ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['order-1'], $ecotone->getQueryBus()->sendWithRouting('placed_orders'));
    }

    private function bootstrap(bool $requireExisting): FlowTestSupport
    {
        $builder = SqsBackedMessageChannelBuilder::create(self::CHANNEL);
        if ($requireExisting) {
            $builder = $builder->withRequiredExistingDestination();
        }

        $handler = new class () {
            public array $orders = [];

            #[Asynchronous('required_orders')]
            #[CommandHandler('place_order', endpointId: 'place_order_handler')]
            public function place(string $orderId): void
            {
                $this->orders[] = $orderId;
            }

            #[QueryHandler('placed_orders')]
            public function placed(): array
            {
                return $this->orders;
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler::class => $handler, SqsConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [$builder],
            enabledModules: [ModulePackageList::SQS_PACKAGE],
        );
    }
}
