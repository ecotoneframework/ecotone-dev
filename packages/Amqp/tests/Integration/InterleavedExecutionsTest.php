<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Fiber;
use Interop\Amqp\Impl\AmqpQueue;
use RuntimeException;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class InterleavedExecutionsTest extends AmqpMessagingTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        try {
            self::getRabbitConnectionFactory()->createContext()->deleteQueue(new AmqpQueue('interleaved_orders'));
        } catch (Throwable) {
        }
    }

    public function test_a_suspended_execution_that_fails_does_not_discard_what_an_interleaved_one_published(): void
    {
        $ecotone = $this->bootstrapOrders(publisherConfirms: false);

        $suspended = new Fiber(function () use ($ecotone): void {
            try {
                $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'refused', 'suspend' => true, 'fail' => true]);
            } catch (RuntimeException) {
            }
        });
        $suspended->start();
        $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'accepted']);
        $suspended->resume();
        $ecotone->run('interleaved_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['accepted' => null], $ecotone->sendQueryWithRouting('order.placed'));
    }

    public function test_an_interleaved_execution_that_fails_does_not_discard_what_the_suspended_one_published(): void
    {
        $ecotone = $this->bootstrapOrders(publisherConfirms: false);

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'accepted', 'suspend' => true], metadata: ['tenant' => 'tenant-of-accepted']));
        $suspended->start();
        try {
            $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'refused', 'fail' => true]);
        } catch (RuntimeException) {
        }
        $suspended->resume();
        $ecotone->run('interleaved_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertSame(['accepted' => 'tenant-of-accepted'], $ecotone->sendQueryWithRouting('order.placed'));
    }

    public function test_interleaved_executions_have_their_own_publishes_confirmed(): void
    {
        $ecotone = $this->bootstrapOrders(publisherConfirms: true);

        $suspended = new Fiber(fn () => $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'suspended', 'suspend' => true]));
        $suspended->start();
        $ecotone->sendCommandWithRouting('order.register', ['orderId' => 'interleaved']);
        $suspended->resume();
        $ecotone->run('interleaved_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertEqualsCanonicalizing(['suspended', 'interleaved'], array_keys($ecotone->sendQueryWithRouting('order.placed')));
    }

    public function test_channel_of_a_finished_execution_is_reused_by_the_next_one(): void
    {
        $ecotone = $this->bootstrapOrders(publisherConfirms: false);

        for ($order = 1; $order <= 2100; $order++) {
            $execution = new Fiber(fn () => $ecotone->sendCommandWithRouting('order.register', ['orderId' => "order-{$order}"]));
            $execution->start();
            $execution = null;
        }
        $ecotone->run('interleaved_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        $this->assertCount(2100, $ecotone->sendQueryWithRouting('order.placed'));
    }

    private function bootstrapOrders(bool $publisherConfirms): FlowTestSupport
    {
        $orders = new class () {
            private array $placed = [];

            #[CommandHandler('order.register')]
            public function register(array $command, CommandBus $commandBus): void
            {
                $commandBus->sendWithRouting('order.place', $command['orderId']);

                if ($command['suspend'] ?? false) {
                    Fiber::suspend();
                }
                if ($command['fail'] ?? false) {
                    throw new RuntimeException('Order was refused');
                }
            }

            #[Asynchronous('interleaved_orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId, #[Header('tenant')] ?string $tenant = null): void
            {
                $this->placed[$orderId] = $tenant;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                return $this->placed;
            }
        };

        return $this->bootstrapFlowTesting(
            [$orders::class],
            [$orders, ...$this->getConnectionFactoryReferences()],
            [
                AmqpConfiguration::createWithDefaults()->withTransactionOnCommandBus(! $publisherConfirms),
                AmqpBackedMessageChannelBuilder::create('interleaved_orders')->withPublisherConfirms($publisherConfirms),
            ],
            [ModulePackageList::AMQP_PACKAGE],
        );
    }
}
