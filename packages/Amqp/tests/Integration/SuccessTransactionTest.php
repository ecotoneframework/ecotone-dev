<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Test\Ecotone\Amqp\AmqpMessagingTest;
use Test\Ecotone\Amqp\Fixture\SuccessTransaction\OrderService;

final class SuccessTransactionTest extends AmqpMessagingTest
{
    public function test_order_is_placed_when_transaction_is_successful(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderService(), AmqpConnectionFactory::class => $this->getCachedConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames([ModulePackageList::JMS_CONVERTER_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withNamespaces(['Test\Ecotone\Amqp\Fixture\SuccessTransaction']),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        self::assertEquals(
            'window',
            $ecotone
                ->sendCommandWithRoutingKey('order.register', 'window')
                ->run('placeOrderEndpoint')
                ->sendQueryWithRouting('order.getOrder')
        );

        self::assertNull(
            $ecotone
                ->run('placeOrderEndpoint')
                ->sendQueryWithRouting('order.getOrder')
        );
    }
}
