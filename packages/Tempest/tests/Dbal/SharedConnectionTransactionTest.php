<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Dbal;

use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\CommandBus;
use Ecotone\Tempest\EcotoneConfig;
use Ecotone\Tempest\EcotoneServiceInitializer;
use Ecotone\Tempest\MessagingSystemInitializer;
use Enqueue\Dbal\DbalConnectionFactory;
use RuntimeException;
use Tempest\Database\Database;
use Tempest\Database\Query;
use Tempest\Database\QueryStatements\CreateTableStatement;
use Test\Ecotone\Tempest\EcotoneIntegrationTestCase;
use Test\Ecotone\Tempest\TempestDatabaseConfigFactory;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class SharedConnectionTransactionTest extends EcotoneIntegrationTestCase
{
    protected function ecotoneConfig(): EcotoneConfig
    {
        return new EcotoneConfig(
            namespaces: ['Test\\Ecotone\\Tempest\\Fixture\\SharedConnection\\'],
            skippedModulePackageNames: ModulePackageList::allPackagesExcept([
                ModulePackageList::TEMPEST_PACKAGE,
                ModulePackageList::DBAL_PACKAGE,
            ]),
            test: false,
        );
    }

    protected function setUp(): void
    {
        EcotoneServiceInitializer::clearCache();
        MessagingSystemInitializer::clearDefinitionHolder();

        $this->setupKernel();

        $this->container->config(TempestDatabaseConfigFactory::primary());

        $database = $this->container->get(Database::class);
        $database->execute(new Query('DROP TABLE IF EXISTS shared_connection_items'));
        $createSql = (new CreateTableStatement('shared_connection_items'))
            ->primary('id')
            ->string('name')
            ->compile($database->dialect);
        $database->execute(new Query($createSql));
    }

    protected function tearDown(): void
    {
        try {
            $database = $this->container->get(Database::class);
            $database->execute(new Query('DROP TABLE IF EXISTS shared_connection_items'));
        } catch (Throwable) {
        }
        parent::tearDown();
    }

    public function test_tempest_model_insert_is_rolled_back_when_ecotone_transaction_fails(): void
    {
        $commandBus = $this->container->get(CommandBus::class);

        try {
            $commandBus->sendWithRouting('shared_connection.insert_then_fail');
        } catch (RuntimeException) {
        }

        $count = $this->container->get(DbalConnectionFactory::class)
            ->createContext()
            ->getDbalConnection()
            ->executeQuery('SELECT COUNT(*) FROM shared_connection_items')
            ->fetchOne();

        $this->assertSame(0, (int) $count, 'Tempest model insert must be rolled back by Ecotone\'s DBAL transaction');
    }
}
