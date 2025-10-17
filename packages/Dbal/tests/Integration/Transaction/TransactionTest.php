<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Ecotone\Dbal\DbalConnection;
use Ecotone\Dbal\DbalTransaction\WithoutDbalTransaction;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Enqueue\Dbal\DbalConnectionFactory;
use Exception;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\Transaction\OrderService;

/**
 * @internal
 */
/**
 * licence Apache-2.0
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

    public function test_it_can_disable_transactions_on_interface(): void
    {
        $consoleCommands = new class () {
            public bool $prepared = false;
            #[CommandHandler('command.prepare')]
            #[WithoutDbalTransaction]
            public function prepare(#[Reference] DbalConnectionFactory $dbalConnectionFactory): void
            {
                $dbalConnectionFactory->createContext()->getDbalConnection()->executeStatement(<<<SQL
                        DROP TABLE IF EXISTS orders
                    SQL);
                $dbalConnectionFactory->createContext()->getDbalConnection()->executeStatement(<<<SQL
                        CREATE TABLE orders (id VARCHAR(255) PRIMARY KEY)
                    SQL);
                $this->prepared = true;
            }

            #[ConsoleCommand('console.register.nontransactional')]
            #[WithoutDbalTransaction]
            public function nontransactional(string $orderId, #[Reference] DbalConnectionFactory $dbalConnectionFactory): void
            {
                $dbalConnectionFactory->createContext()->getDbalConnection()->executeStatement(<<<SQL
                        INSERT INTO orders VALUES (:orderId)
                    SQL, ['orderId' => $orderId]);
                throw new Exception('Force rollback');
            }

            #[ConsoleCommand('console.register.transactional')]
            public function transactional(string $orderId, #[Reference] DbalConnectionFactory $dbalConnectionFactory): void
            {
                $dbalConnectionFactory->createContext()->getDbalConnection()->executeStatement(<<<SQL
                        INSERT INTO orders VALUES (:orderId)
                    SQL, ['orderId' => $orderId]);
                throw new Exception('Force rollback');
            }

            #[QueryHandler('hasOrder')]
            public function hasOrder(string $orderId, #[Reference] DbalConnectionFactory $dbalConnectionFactory): bool
            {
                $result = $dbalConnectionFactory->createContext()->getDbalConnection()->fetchOne(<<<SQL
                        SELECT COUNT(*) FROM orders WHERE id = :orderId
                    SQL, ['orderId' => $orderId]);

                return $result > 0;
            }
        };
        $dbalConnectionFactory = $this->getConnectionFactory();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$consoleCommands::class],
            [$consoleCommands, DbalConnectionFactory::class => $dbalConnectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
        );
        $ecotone->sendCommandWithRoutingKey('command.prepare');
        $this->assertSame(true, $consoleCommands->prepared, 'Preparation command should be executed');

        try {
            $ecotone->runConsoleCommand('console.register.nontransactional', ['orderId' => 'non-transactional-order-id']);
            $this->fail('Exception should be thrown');
        } catch (Exception) {
            // Expected exception
        }
        $this->assertTrue(
            $ecotone->sendQueryWithRouting('hasOrder', 'non-transactional-order-id'),
            'Non transactional command should pass without transaction and commit data'
        );


        try {
            $ecotone->runConsoleCommand('console.register.transactional', ['orderId' => 'transactional-order-id']);
            $this->fail('Exception should be thrown');
        } catch (Exception) {
            // Expected exception
        }
        $this->assertFalse(
            $ecotone->sendQueryWithRouting('hasOrder', 'transactional-order-id'),
            'Transactional command should rollback data'
        );
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
