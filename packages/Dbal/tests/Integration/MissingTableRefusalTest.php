<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Closure;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\Database\DeadLetterTableManager;
use Ecotone\Dbal\Database\DeduplicationTableManager;
use Ecotone\Dbal\Database\DocumentStoreTableManager;
use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\Dbal\Deduplication\DbalHandledMessages;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Dbal\Recoverability\DbalDeadLetterStore;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Test\StubLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class MissingTableRefusalTest extends DbalMessagingTestCase
{
    public static function documentStoreOperations(): iterable
    {
        yield 'add' => [fn (DocumentStore $store) => $store->addDocument('orders', 'o-1', '{"product":"milk"}')];
        yield 'update' => [fn (DocumentStore $store) => $store->updateDocument('orders', 'o-1', '{"product":"milk"}', 1)];
        yield 'upsert' => [fn (DocumentStore $store) => $store->upsertDocument('orders', 'o-1', '{"product":"milk"}', 0)];
        yield 'get' => [fn (DocumentStore $store) => $store->getDocument('orders', 'o-1')];
        yield 'find' => [fn (DocumentStore $store) => $store->findDocument('orders', 'o-1')];
        yield 'get version' => [fn (DocumentStore $store) => $store->getDocumentVersion('orders', 'o-1')];
        yield 'get all' => [fn (DocumentStore $store) => $store->getAllDocuments('orders')];
        yield 'count' => [fn (DocumentStore $store) => $store->countDocuments('orders')];
        yield 'delete' => [fn (DocumentStore $store) => $store->deleteDocument('orders', 'o-1')];
        yield 'drop collection' => [fn (DocumentStore $store) => $store->dropCollection('orders')];
    }

    #[DataProvider('documentStoreOperations')]
    public function test_every_document_store_operation_names_the_setup_when_its_table_is_missing(Closure $operation): void
    {
        $ecotone = $this->bootstrapWithoutTables(DbalConfiguration::createWithDefaults());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(DocumentStoreTableManager::FEATURE_NAME, DbalDocumentStore::ECOTONE_DOCUMENT_STORE, null));

        $operation($ecotone->getGateway(DocumentStore::class));
    }

    public static function deadLetterOperations(): iterable
    {
        yield 'store' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->store(MessageBuilder::withPayload('failed')->build())];
        yield 'list' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->list(10, 0)];
        yield 'count' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->count()];
        yield 'show' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->show('m-1')];
        yield 'replay' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->replay('m-1')];
        yield 'replay all' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->replayAll()];
        yield 'delete' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->delete('m-1')];
        yield 'delete all' => [fn (DeadLetterGateway $deadLetter) => $deadLetter->deleteAll()];
    }

    #[DataProvider('deadLetterOperations')]
    public function test_every_dead_letter_operation_names_the_setup_when_its_table_is_missing(Closure $operation): void
    {
        $ecotone = $this->bootstrapWithoutTables(DbalConfiguration::createWithDefaults());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(DeadLetterTableManager::FEATURE_NAME, DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE, null));

        $operation($ecotone->getGateway(DeadLetterGateway::class));
    }

    public function test_storing_a_dead_letter_whose_table_is_missing_is_refused_without_retrying(): void
    {
        $logger = StubLogger::create();
        $ecotone = $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory(), 'logger' => $logger],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults()]),
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
        );

        try {
            $ecotone->getGateway(DeadLetterGateway::class)->store(MessageBuilder::withPayload('failed')->build());
            self::fail('Expected storing a dead letter without its table to be refused');
        } catch (ConfigurationException $exception) {
            self::assertSame(MissingTableInstructions::build(DeadLetterTableManager::FEATURE_NAME, DbalDeadLetterStore::DEFAULT_DEAD_LETTER_TABLE, null), $exception->getMessage());
        }

        self::assertSame([], $logger->getInfo());
    }

    public function test_removing_expired_deduplication_entries_names_the_setup_when_deduplication_is_used_and_its_table_is_missing(): void
    {
        $ecotone = $this->bootstrapWithoutTables(DbalConfiguration::createWithDefaults()->withDeduplication(true));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(DeduplicationTableManager::FEATURE_NAME, DbalHandledMessages::DEFAULT_DEDUPLICATION_TABLE, null));

        $ecotone->runConsoleCommand('ecotone:deduplication:remove-expired-messages', []);
    }

    public function test_removing_expired_deduplication_entries_does_nothing_when_deduplication_is_not_used(): void
    {
        $ecotone = $this->bootstrapWithoutTables(DbalConfiguration::createWithDefaults()->withDeduplication(false));

        $ecotone->runConsoleCommand('ecotone:deduplication:remove-expired-messages', []);

        self::assertFalse(self::checkIfTableExists($this->getConnection(), DbalHandledMessages::DEFAULT_DEDUPLICATION_TABLE));
    }

    public function test_sending_to_an_asynchronous_handler_names_the_message_queue_setup_when_its_table_is_missing(): void
    {
        $ecotone = $this->bootstrapAsynchronousHandlerWithoutTables();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(EnqueueTableManager::FEATURE_NAME, EnqueueTableManager::DEFAULT_TABLE_NAME, null));

        $ecotone->sendCommandWithRouting('order.place', 'milk');
    }

    public function test_consuming_an_asynchronous_channel_names_the_message_queue_setup_when_its_table_is_missing(): void
    {
        $ecotone = $this->bootstrapAsynchronousHandlerWithoutTables();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(MissingTableInstructions::build(EnqueueTableManager::FEATURE_NAME, EnqueueTableManager::DEFAULT_TABLE_NAME, null));

        $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
    }

    private function bootstrapAsynchronousHandlerWithoutTables(): FlowTestSupport
    {
        $handler = new class () {
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $product): void
            {
            }
        };

        return $this->bootstrapFlowTesting(
            [$handler::class],
            containerOrAvailableServices: [$handler, DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([DbalConfiguration::createWithDefaults(), DbalBackedMessageChannelBuilder::create('orders')]),
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
        );
    }

    private function bootstrapWithoutTables(DbalConfiguration $dbalConfiguration): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE])
                ->withExtensionObjects([$dbalConfiguration]),
            testConfiguration: TestConfiguration::createWithDefaults()->withDatabaseTablesSetUpOnBootstrap(false),
        );
    }
}
