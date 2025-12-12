<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
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
        if (getenv('AMQP_IMPLEMENTATION') === 'lib') {
            $this->markTestSkipped('Transaction tests require Ext');
        }

        $ecotone = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderService(), ...$this->getConnectionFactoryReferences()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
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
