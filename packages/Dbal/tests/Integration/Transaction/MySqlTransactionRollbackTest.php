<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MySqlTransactionRollbackTest extends TestCase
{
    public const ORDERS_TABLE = 'implicit_commit_removal_orders';

    protected function setUp(): void
    {
        $connection = $this->createConnectionFactory()->createContext()->getDbalConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS ' . self::ORDERS_TABLE);
        $connection->executeStatement('CREATE TABLE ' . self::ORDERS_TABLE . ' (id VARCHAR(255) PRIMARY KEY)');
    }

    public function test_write_before_a_thrown_exception_is_rolled_back_on_mysql(): void
    {
        $ecotone = $this->bootstrapEcotone();

        try {
            $ecotone->sendCommandWithRouting('order.registerThenFail', 'milk');
            $this->fail('Exception should have been thrown');
        } catch (Exception) {
        }

        $this->assertSame([], $ecotone->sendQueryWithRouting('order.getRegistered'));
    }

    public function test_two_sequential_messages_each_get_their_own_transaction_on_mysql(): void
    {
        $ecotone = $this->bootstrapEcotone();

        $ecotone->sendCommandWithRouting('order.register', 'bread');
        $this->assertSame(['bread'], $ecotone->sendQueryWithRouting('order.getRegistered'));

        try {
            $ecotone->sendCommandWithRouting('order.registerThenFail', 'milk');
            $this->fail('Exception should have been thrown');
        } catch (Exception) {
        }

        $this->assertSame(['bread'], $ecotone->sendQueryWithRouting('order.getRegistered'));
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $orderService = new class () {
            #[CommandHandler('order.register')]
            public function register(string $order, #[Reference] DbalConnectionFactory $connectionFactory): void
            {
                $connectionFactory->createContext()->getDbalConnection()->executeStatement(
                    'INSERT INTO ' . MySqlTransactionRollbackTest::ORDERS_TABLE . ' VALUES (:order)',
                    ['order' => $order]
                );
            }

            #[CommandHandler('order.registerThenFail')]
            public function registerThenFail(string $order, #[Reference] DbalConnectionFactory $connectionFactory): void
            {
                $connectionFactory->createContext()->getDbalConnection()->executeStatement(
                    'INSERT INTO ' . MySqlTransactionRollbackTest::ORDERS_TABLE . ' VALUES (:order)',
                    ['order' => $order]
                );

                throw new Exception('Simulated failure after write');
            }

            #[QueryHandler('order.getRegistered')]
            public function getRegistered(#[Reference] DbalConnectionFactory $connectionFactory): array
            {
                return $connectionFactory->createContext()->getDbalConnection()
                    ->executeQuery('SELECT id FROM ' . MySqlTransactionRollbackTest::ORDERS_TABLE)
                    ->fetchFirstColumn();
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$orderService::class],
            containerOrAvailableServices: [$orderService, DbalConnectionFactory::class => $this->createConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE]),
        );
    }

    private function createConnectionFactory(): DbalConnectionFactory
    {
        return new DbalConnectionFactory(
            getenv('DATABASE_MYSQL') ?: 'mysql://ecotone:secret@localhost:3306/ecotone?serverVersion=8.0'
        );
    }
}
