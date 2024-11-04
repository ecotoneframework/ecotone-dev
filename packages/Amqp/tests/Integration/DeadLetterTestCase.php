<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;
use Test\Ecotone\Amqp\Fixture\DeadLetter\OrderService;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DeadLetterTestCase extends AmqpMessagingTestCase
{
    public function test_exception_handling_with_retries_and_dead_letter(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderService(), AmqpConnectionFactory::class => $this->getCachedConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::AMQP_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\Amqp\Fixture\DeadLetter']),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $ecotone
            ->sendCommandWithRoutingKey('order.register', 'coffee')
            ->run('correctOrders')
            ->run('incorrectOrdersEndpoint')
        ;

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getIncorrectOrderAmount'));

        $ecotone
            ->run('correctOrders')
            ->run('incorrectOrdersEndpoint')
        ;

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        self::assertEquals(0, $ecotone->sendQueryWithRouting('getIncorrectOrderAmount'));

        $ecotone
            ->run('correctOrders')
            ->run('incorrectOrdersEndpoint')
        ;

        self::assertEquals(0, $ecotone->sendQueryWithRouting('getOrderAmount'));
        self::assertEquals(1, $ecotone->sendQueryWithRouting('getIncorrectOrderAmount'));
    }
}
