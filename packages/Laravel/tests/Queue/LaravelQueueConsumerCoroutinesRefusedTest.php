<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Queue;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\DynamicMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Laravel\LaravelQueueMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Test\Ecotone\Laravel\RestoresLaravelHandlers;

/**
 * licence Apache-2.0
 * @internal
 */
final class LaravelQueueConsumerCoroutinesRefusedTest extends TestCase
{
    use RestoresLaravelHandlers;

    private const REFUSAL = 'is set to consume on 3 coroutines, but it consumes Laravel queue channel `orders`, whose connection `database` Laravel keeps one of for the whole process: every coroutine would read from that one connection at the same time, which stops the process or mixes their transactions. '
        . 'Leave out the `coroutines` option of ecotone:run, ExecutionPollingMetadata::withCoroutines() and PollingMetadata::withCoroutines() for this consumer. '
        . 'To handle more messages at once, start more processes of this consumer, or move its handlers to a channel Ecotone connects to itself (DbalBackedMessageChannelBuilder with a connection registered from a DSN, AmqpBackedMessageChannelBuilder, RedisBackedMessageChannelBuilder or SqsBackedMessageChannelBuilder) and consume that channel on coroutines.';

    public function test_consumer_of_a_laravel_queue_channel_is_refused_to_consume_on_coroutines_passed_when_running_it(): void
    {
        $ecotone = $this->bootstrapOrders();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Consumer `orders` ' . self::REFUSAL);

        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
    }

    public function test_consumer_of_a_laravel_queue_channel_with_coroutines_in_its_polling_metadata_is_refused_when_the_application_boots(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Consumer `orders` ' . self::REFUSAL);

        $this->bootstrapOrders(PollingMetadata::create('orders')->withCoroutines(3));
    }

    public function test_consumer_of_a_dynamic_channel_receiving_from_a_laravel_queue_channel_is_refused_when_the_application_boots(): void
    {
        $orders = new class () {
            #[Asynchronous('orders_of_every_region')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Consumer `orders_of_every_region` ' . self::REFUSAL);

        EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            $this->laravelWith($orders),
            extensionObjects: [
                DynamicMessageChannelBuilder::createRoundRobin('orders_of_every_region', ['orders']),
                LaravelQueueMessageChannelBuilder::create('orders', 'database'),
                PollingMetadata::create('orders_of_every_region')->withCoroutines(3),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function bootstrapOrders(object ...$extensionObjects): FlowTestSupport
    {
        $orders = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId): void
            {
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            $this->laravelWith($orders),
            extensionObjects: [LaravelQueueMessageChannelBuilder::create('orders', 'database'), ...$extensionObjects],
            enabledModules: [],
        );
    }

    private function laravelWith(object $service): ContainerInterface
    {
        $app = require __DIR__ . '/../Application/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->instance($service::class, $service);

        return $app;
    }
}
