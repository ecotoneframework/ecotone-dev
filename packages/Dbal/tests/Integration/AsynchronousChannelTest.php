<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Dbal\DbalConnection;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Exception;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor\AddMetadataInterceptor;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class AsynchronousChannelTest extends DbalMessagingTestCase
{
    public function test_it_will_rollback_the_message_when_it_fails_at_second_time_to_add_the_order(): void
    {
        $ecotone = $this->bootstrapEcotone(
            services: [
                new \Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction\OrderService(),
            ],
            namespaces: ['Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction']
        );

        self::assertCount(0, $ecotone
            ->sendCommandWithRoutingKey('order.register', 'milk')
            ->sendQueryWithRouting('order.getRegistered'));

        self::assertCount(1, $ecotone
            ->run('orders')
            ->run('processOrders')
            ->sendQueryWithRouting('order.getRegistered'));

        self::assertCount(1, $ecotone
            ->sendCommandWithRoutingKey('order.register', 'milk')
            ->run('orders')
            ->run('processOrders')
            ->sendQueryWithRouting('order.getRegistered'));
    }

    public function test_handling_commit_transaction_that_were_ended_by_implicit_commit_test_for_non_ddl_transactional_databases(): void
    {
        $ecotone = $this->bootstrapEcotone(
            services: [
                new \Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction\OrderService(),
            ],
            namespaces: ['Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction']
        );

        self::assertCount(1, $ecotone
            ->sendCommandWithRoutingKey('order.register_with_table_creation', 'milk')
            ->run('orders')
            ->run('processOrders')
            ->sendQueryWithRouting('order.getRegistered'));
    }

    public function test_handling_rollback_transaction_that_was_caused_by_non_ddl_statement_with_success_later(): void
    {
        $ecotone = $this->bootstrapEcotone(
            services: [
                new \Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction\OrderService(),
            ],
            namespaces: ['Test\Ecotone\Dbal\Fixture\AsynchronousChannelTransaction']
        );

        try {
            $ecotone->sendCommandWithRoutingKey('order.prepareWithFailure');
        } catch (Exception) {
        }

        self::assertCount(1, $ecotone
            ->sendCommandWithRoutingKey('order.register', 'milk')
            ->run('orders')
            ->run('processOrders')
            ->sendQueryWithRouting('order.getRegistered'));
    }

    public function test_working_with_asynchronous_channel_and_interceptor(): void
    {
        $ecotone = $this->bootstrapEcotone(
            services: [
                new AddMetadataInterceptor(),
                new \Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor\OrderService(),
            ],
            namespaces: ['Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor']
        );

        $ecotone->sendCommandWithRoutingKey('order.register', 'milk');
        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered'));

        $ecotone->run('orders');
        self::assertCount(1, $ecotone->sendQueryWithRouting('order.getRegistered'));
    }

    public function test_working_with_asynchronous_channel_and_already_connected_factory(): void
    {
        $connection = $this->getConnection();
        $connection->close();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices:
                array_merge(
                    [
                        new AddMetadataInterceptor(),
                        new \Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor\OrderService(),
                    ],
                    [
                        DbalConnectionFactory::class => DbalConnection::create(
                            $connection
                        ),
                    ]
                ),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor']),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $ecotone->sendCommandWithRoutingKey('order.register', 'milk');
        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered'));

        $ecotone->run('orders');
        self::assertCount(1, $ecotone->sendQueryWithRouting('order.getRegistered'));
    }

    private function bootstrapEcotone(array $services, array $namespaces): FlowTestSupport
    {
        $dbalConnectionFactory = $this->getConnectionFactory();

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: array_merge($services, [DbalConnectionFactory::class => DbalConnection::fromConnectionFactory($dbalConnectionFactory), 'managerRegistry' => $dbalConnectionFactory]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces($namespaces),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
