<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Exception;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\Transaction\OrderService;

/**
 * @internal
 */
final class TransactionTest extends DbalMessagingTestCase
{
    public function test_ordering_with_transaction_a_product_with_failure_so_the_order_should_never_be_committed_to_database(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $ecotone->sendCommandWithRoutingKey('order.prepare');

        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered'));

        try {
            $ecotone->sendCommandWithRoutingKey('order.register', 'milk');
        } catch (Exception) {
        }

        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered'));
    }

    public function test_ordering_with_transaction_a_product_with_failure_so_the_order_should_never_be_committed_to_database_with_tenant_connection(): void
    {
        $ecotone = $this->bootstrapEcotoneWithMultiTenantConnection();

        $ecotone->sendCommandWithRoutingKey('order.prepare', metadata: ['tenant' => 'tenant_a']);
        $ecotone->sendCommandWithRoutingKey('order.prepare', metadata: ['tenant' => 'tenant_b']);

        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered', metadata: ['tenant' => 'tenant_a']));
        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered', metadata: ['tenant' => 'tenant_b']));

        try {
            $ecotone->sendCommandWithRoutingKey('order.register', 'milk', metadata: ['tenant' => 'tenant_a']);
        } catch (Exception) {
        }
        try {
            $ecotone->sendCommandWithRoutingKey('order.register', 'milk', metadata: ['tenant' => 'tenant_b']);
        } catch (Exception) {
        }

        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered', metadata: ['tenant' => 'tenant_a']));
        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered', metadata: ['tenant' => 'tenant_b']));
    }

    public function test_transactions_from_existing_connection(): void
    {
        $connection = $this->getConnection();
        $connection->close();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                new OrderService(),
                DbalConnectionFactory::class => DbalConnection::create(
                    $connection
                ),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\Transaction',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );

        try {
            $ecotone->sendCommandWithRoutingKey('order.prepare');
        } catch (Exception) {
        }

        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered'));

        try {
            $ecotone->sendCommandWithRoutingKey('order.register', 'milk');
        } catch (Exception) {
        }

        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered'));
    }

    public function test_handling_rollback_transaction_that_was_caused_by_non_ddl_statement_ending_with_failure_later(): void
    {
        $ecotone = $this->bootstrapEcotone();

        try {
            $ecotone->sendCommandWithRoutingKey('order.prepareWithFailure');
        } catch (Exception) {
        }

        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered'));

        try {
            $ecotone->sendCommandWithRoutingKey('order.register', 'milk');
        } catch (Exception) {
        }

        self::assertCount(0, $ecotone->sendQueryWithRouting('order.getRegistered'));
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $dbalConnectionFactory = $this->getConnectionFactory();

        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [new OrderService(), DbalConnectionFactory::class => DbalConnection::fromConnectionFactory($dbalConnectionFactory), 'managerRegistry' => $dbalConnectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\Transaction',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }

    private function bootstrapEcotoneWithMultiTenantConnection(): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            containerOrAvailableServices: [
                new OrderService(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    MultiTenantConfiguration::create(
                        'tenant',
                        [
                            'tenant_a' => 'tenant_a_connection',
                            'tenant_b' => 'tenant_b_connection',
                        ]
                    ),
                ])
                ->withNamespaces([
                    'Test\Ecotone\Dbal\Fixture\Transaction',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }
}
