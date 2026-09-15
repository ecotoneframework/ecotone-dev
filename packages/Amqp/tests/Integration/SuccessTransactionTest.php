<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Api\ServiceConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\SuccessTransaction\OrderService;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class SuccessTransactionTest extends AmqpMessagingTestCase
{
    public function test_order_is_placed_when_transaction_is_successful(): void
    {
        $ecotone = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderService(), ...$this->getConnectionFactoryReferences()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::AMQP_PACKAGE, ])
                ->withNamespaces(['Test\Ecotone\Amqp\Fixture\SuccessTransaction']),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        self::assertEquals(
            'window',
            $ecotone
                ->sendCommandWithRouting('order.register', 'window')
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
