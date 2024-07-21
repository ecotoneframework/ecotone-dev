<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction\OrderRegisteringGateway;
use Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction\OrderService;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class ReconnectTest extends DbalMessagingTestCase
{
    public function test_it_will_automatically_reconnect(): void
    {
        $connectionFactory = $this->connectionForTenantA();
        $ecotone = $this->bootstrapEcotone([
            DbalConnectionFactory::class => $connectionFactory,
        ]);

        self::assertCount(0, $ecotone
            ->sendCommandWithRoutingKey('order.register', 'milk')
            ->sendQueryWithRouting('order.getRegistered'));

        $connectionFactory->createContext()->getDbalConnection()->close();

        self::assertCount(1, $ecotone
            ->run('orders')
            ->run('processOrders')
            ->sendQueryWithRouting('order.getRegistered'));
    }

    public function test_it_will_automatically_reconnect_for_multi_tenant_connection(): void
    {
        $connectionFactory = $this->connectionForTenantA();
        $ecotone = $this->bootstrapEcotone([
            'tenant_a_connection' => $connectionFactory,
        ], [
            MultiTenantConfiguration::create(
                'tenant',
                [
                    'tenant_a' => 'tenant_a_connection',
                ],
            ),
        ]);

        self::assertCount(0, $ecotone
            ->sendCommandWithRoutingKey('order.register', 'milk', metadata: ['tenant' => 'tenant_a'])
            ->sendQueryWithRouting('order.getRegistered', metadata: ['tenant' => 'tenant_a']));

        $connectionFactory->createContext()->getDbalConnection()->close();

        self::assertCount(1, $ecotone
            ->run('orders')
            ->run('processOrders')
            ->sendQueryWithRouting('order.getRegistered', metadata: ['tenant' => 'tenant_a']));
    }

    private function bootstrapEcotone(array $services, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, OrderRegisteringGateway::class],
            containerOrAvailableServices: array_merge($services, [new OrderService()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects(array_merge([
                    DbalBackedMessageChannelBuilder::create('orders'),
                    DbalBackedMessageChannelBuilder::create('processOrders'),
                ], $extensionObjects)),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
