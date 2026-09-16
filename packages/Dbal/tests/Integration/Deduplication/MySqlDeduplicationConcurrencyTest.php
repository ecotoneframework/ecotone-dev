<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Deduplication;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\Database\DeduplicationTableManager;
use Ecotone\Dbal\Deduplication\DeduplicationInterceptor;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\MessageHeaders;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MySqlDeduplicationConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        $connection = $this->createConnectionFactory()->createContext()->getDbalConnection();
        if ($connection->createSchemaManager()->tablesExist([DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE])) {
            $connection->executeStatement('DROP TABLE ' . DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE);
        }

        (new DeduplicationTableManager(DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE, true, true, null))
            ->createTable($connection);
    }

    public function test_deduplication_row_is_inserted_before_the_handler_runs_on_mysql_when_a_transaction_is_active(): void
    {
        $connectionFactory = $this->createConnectionFactory();
        $executionOrder = [];

        $handler = new class ($executionOrder) {
            public function __construct(public array &$executionOrder)
            {
            }

            #[Deduplicated]
            #[CommandHandler('handle', endpointId: 'handler_endpoint')]
            public function handle(#[Reference] DbalConnectionFactory $connectionFactory): void
            {
                $connection = $connectionFactory->createContext()->getDbalConnection();
                $alreadyInserted = (bool) $connection->fetchOne(
                    'SELECT COUNT(*) FROM ' . DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE
                );
                $this->executionOrder[] = $alreadyInserted ? 'row_present_before_handler' : 'row_missing_before_handler';
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $connectionFactory],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true)]),
        );

        $ecotone->sendCommandWithRouting('handle', metadata: [MessageHeaders::MESSAGE_ID => 'message-1']);

        $this->assertSame(['row_present_before_handler'], $executionOrder);
    }

    public function test_a_second_insert_for_the_same_deduplication_key_is_rejected_by_mysql(): void
    {
        $connectionFactory = $this->createConnectionFactory();
        $connection = $connectionFactory->createContext()->getDbalConnection();
        $tableManager = new DeduplicationTableManager(DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE, true, true, null);
        $tableManager->createTable($connection);

        $row = [
            'message_id' => 'message-1',
            'consumer_endpoint_id' => 'handler_endpoint',
            'routing_slip' => '',
            'handled_at' => 0,
        ];
        $connection->insert(DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE, $row);

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert(DeduplicationInterceptor::DEFAULT_DEDUPLICATION_TABLE, $row);
    }

    private function createConnectionFactory(): DbalConnectionFactory
    {
        return new DbalConnectionFactory(
            getenv('DATABASE_MYSQL') ?: 'mysql://ecotone:secret@localhost:3306/ecotone?serverVersion=8.0'
        );
    }
}
