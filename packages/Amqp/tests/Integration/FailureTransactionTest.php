<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Test\Ecotone\Amqp\AmqpMessagingTest;

/**
 * @internal
 */
final class FailureTransactionTest extends AmqpMessagingTest
{
    public function test_order_is_never_placed_when_transaction_is_failed(): void
    {
        $ecotone = $this->bootstrapEcotone(
            ['Test\Ecotone\Amqp\Fixture\FailureTransaction'],
            [new \Test\Ecotone\Amqp\Fixture\FailureTransaction\OrderService()]
        );

        try {
            $ecotone->sendCommandWithRoutingKey('order.register', 'milk');
        } catch (\Throwable) {
        }

        try {
            $ecotone->sendCommandWithRoutingKey('order.register', 'milk');
        } catch (\Throwable) {
        }

        self::assertNull(
            $ecotone
                ->run('placeOrder')
                ->sendQueryWithRouting('order.getOrder')
        );
    }

    public function test_order_is_never_placed_when_transaction_is_failed_with_fatal_error(): void
    {
        $ecotone = $this->bootstrapEcotone(
            ['Test\Ecotone\Amqp\Fixture\FailureTransactionWithFatalError'],
            [new \Test\Ecotone\Amqp\Fixture\FailureTransactionWithFatalError\OrderService()]
        );

        self::assertNull(
            $ecotone
                ->sendCommandWithRoutingKey('order.register', 'window')
                ->run('placeOrder')
                ->run('placeOrder')
                ->sendQueryWithRouting('order.getOrder')
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
