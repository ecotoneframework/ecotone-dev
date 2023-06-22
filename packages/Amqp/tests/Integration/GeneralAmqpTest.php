<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Test\Ecotone\Amqp\AmqpMessagingTest;
use Test\Ecotone\Amqp\Fixture\Order\OrderErrorHandler;
use Test\Ecotone\Amqp\Fixture\Order\OrderService;
use Test\Ecotone\Amqp\Fixture\Shop\ShoppingCart;

/**
 * @internal
 */
final class GeneralAmqpTest extends AmqpMessagingTest
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

    private function bootstrapEcotone(array $namespaces, array $services): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge([AmqpConnectionFactory::class => $this->getCachedConnectionFactory()], $services),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames([ModulePackageList::JMS_CONVERTER_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces($namespaces),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
