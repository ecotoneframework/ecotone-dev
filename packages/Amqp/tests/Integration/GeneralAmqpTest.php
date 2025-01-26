<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\Order\OrderErrorHandler;
use Test\Ecotone\Amqp\Fixture\Order\OrderService;
use Test\Ecotone\Amqp\Fixture\Shop\ShoppingCart;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class GeneralAmqpTest extends AmqpMessagingTestCase
{
    public function test_products_are_on_list_after_being_ordered(): void
    {
        $ecotone = $this->bootstrapEcotone(
            namespaces: ['Test\Ecotone\Amqp\Fixture\Order'],
            services: [new OrderService(), new OrderErrorHandler()],
        );

        $ecotone->sendCommandWithRoutingKey('order.register', 'milk');
        self::assertEquals(
            [],
            $ecotone->sendQueryWithRouting('order.getOrders')
        );
        $ecotone->run('orders');
        self::assertEquals(
            ['milk'],
            $ecotone->sendQueryWithRouting('order.getOrders')
        );
    }

    public function test_messages_are_delivered_after_lost_heartbeat(): void
    {
        $ecotone = $this->bootstrapEcotone(
            namespaces: ['Test\Ecotone\Amqp\Fixture\Order'],
            services: [new OrderService(), new OrderErrorHandler(), 'logger' => new EchoLogger()],
            amqpConfig: ['heartbeat' => 2]
        );

        $ecotone->sendCommandWithRoutingKey('order.register', 'milk');
        sleep(5);
        $ecotone->sendCommandWithRoutingKey('order.register', 'salt');
        sleep(5);
        $ecotone->sendCommandWithRoutingKey('order.register', 'sunflower');
        $ecotone->run('orders');
        $ecotone->run('orders');
        $ecotone->run('orders');
        self::assertEquals(['milk', 'salt', 'sunflower'], $ecotone->sendQueryWithRouting('order.getOrders'));
    }

    public function test_adding_product_to_shopping_cart_with_publisher_and_consumer(): void
    {
        $ecotone = $this->bootstrapEcotone(
            namespaces: ['Test\Ecotone\Amqp\Fixture\Shop'],
            services: [new ShoppingCart()],
        );

        self::assertEquals(
            ['window'],
            $ecotone
                ->sendCommandWithRoutingKey('addToBasket', 'window')
                ->run('addToCart')
                ->sendQueryWithRouting('getShoppingCartList')
        );
    }

    private function bootstrapEcotone(array $namespaces, array $services, array $amqpConfig = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge([AmqpConnectionFactory::class => $this->getCachedConnectionFactory($amqpConfig)], $services),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces($namespaces),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
