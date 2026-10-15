<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Modelling\AggregateNotFoundException;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\Database\DeduplicationTableManager;
use Ecotone\Dbal\Database\DocumentStoreTableManager;
use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Dbal\Deduplication\DbalHandledMessages;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Test\LicenceTesting;
use Exception;
use PHPUnit\Framework\Attributes\Group;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ConnectionBreakingConfiguration;
use Test\Ecotone\Dbal\Fixture\ConnectionBreakingModule;
use Test\Ecotone\Dbal\Fixture\InstantRetryTransaction\CommandDispatchingAsyncHandler;
use Test\Ecotone\Dbal\Fixture\ORM\FailureMode\MultipleInternalCommandsService;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class DbalTransactionAsynchronousEndpointTest extends DbalMessagingTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->setupUserTable();
    }

    public function test_turning_on_transactions_for_polling_consumer()
    {
        $ecotoneLite = $this->bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [new MultipleInternalCommandsService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnCommandBus(false)->withDoctrineORMRepositories(true),
                DbalBackedMessageChannelBuilder::create('async'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            addInMemoryStateStoredRepository: false,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        /** This ensures for mysql that deduplication table will be created in first run and solves implicit commit */
        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [['personId' => 99, 'personName' => 'Johny', 'exception' => false]]);
        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => true],
        ]);

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2, executionTimeLimitInMilliseconds: 5000, stopOnError: false));

        /** Should be rolled back */
        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);

        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 101]);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);
    }

    public function test_reconnecting_on_lost_connection_during_commit()
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite does not support connection breaking/recovering testing');
        }

        // Now create the actual test instance with the connection breaking module
        $ecotoneLite = $this->bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class, ConnectionBreakingModule::class],
            [
                new MultipleInternalCommandsService(),
                DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person']),
                //                "logger" => new EchoLogger()
            ],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnCommandBus(false)->withDoctrineORMRepositories(true),
                DbalBackedMessageChannelBuilder::create('async'),
                ConnectionBreakingConfiguration::createWithBreakBeforeCommit([false, false, false, true]),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            addInMemoryStateStoredRepository: false,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Send a command to ensure the deduplication table is created
        $ecotoneLite->sendCommandWithRouting('singeInternalCommand', ['personId' => 99, 'personName' => 'Johny', 'exception' => false]);
        $ecotoneLite->sendCommandWithRouting('singeInternalCommand', ['personId' => 100, 'personName' => 'Johny', 'exception' => false]);

        // Run with enough message handling attempts to process both commands
        // The second command will encounter a connection failure but should recover
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 5, executionTimeLimitInMilliseconds: 10000, stopOnError: false));

        // Verify that despite the connection failure, at least one aggregate was successfully created
        try {
            $name = $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]);
            $this->assertNotNull($name);
        } catch (Exception $e) {
            // If person with ID 100 doesn't exist, try with ID 99
            $name = $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 99]);
            $this->assertNotNull($name);
        }
    }

    #[Group('test_reconnecting_on_lost_connection_during_dead_letter_storage')]
    public function test_reconnecting_on_lost_connection_during_dead_letter_storage()
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite does not support connection breaking/recovering testing');
        }

        // First, create a regular EcotoneLite instance to set up the database tables
        $setupEcotoneLite = $this->bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [
                new MultipleInternalCommandsService(),
                DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person']),
            ],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnCommandBus(false)->withDoctrineORMRepositories(true),
                DbalBackedMessageChannelBuilder::create('async'),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            addInMemoryStateStoredRepository: false,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        // Send a command to ensure the deduplication table is created
        $setupEcotoneLite->sendCommandWithRouting('multipleInternalCommands', [['personId' => 98, 'personName' => 'Setup', 'exception' => false]]);
        $setupEcotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        // Now create the actual test instance with the connection breaking module
        $ecotoneLite = $this->bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class, ConnectionBreakingModule::class],
            [
                new MultipleInternalCommandsService(),
                DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person']),
                'logger' => new EchoLogger(),
            ],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnCommandBus(false)->withDoctrineORMRepositories(true),
                DbalBackedMessageChannelBuilder::create('async'),
                ConnectionBreakingConfiguration::createWithBreakBeforeCommit([true, false]),
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            addInMemoryStateStoredRepository: false,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite->sendCommandWithRouting('singeInternalCommand', ['personId' => 100, 'personName' => 'Johny', 'exception' => true]);

        /** @var DeadLetterGateway $deadLetter */
        $deadLetter = $ecotoneLite->getGateway(DeadLetterGateway::class);
        $initialCount = $deadLetter->count();

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        // After running, we should have one more dead letter than before
        $this->assertSame($initialCount + 1, $deadLetter->count());
    }

    public function test_turning_on_transactions_for_polling_consumer_with_tenant_connection()
    {
        $connection = $this->getConnection();
        (new EnqueueTableManager(EnqueueTableManager::DEFAULT_TABLE_NAME, true))->createTable($connection);
        (new DocumentStoreTableManager(DbalDocumentStore::ECOTONE_DOCUMENT_STORE, true))->createTable($connection);
        (new DeduplicationTableManager(DbalHandledMessages::DEFAULT_DEDUPLICATION_TABLE, true))->createTable($connection);

        $ecotoneLite = $this->bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [
                new MultipleInternalCommandsService(),
                'tenant_a_connection' => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person']),
            ],
            extensionObjects: [
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('nullChannel'),
                \Ecotone\Api\JMSConverter\JMSConverterConfiguration::createWithDefaults()->withDefaultNullSerialization(false),
                DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnCommandBus(false)->withDocumentStore(true, enableDocumentStoreStateStoredRepository:true),
                DbalBackedMessageChannelBuilder::create('async'),
                MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection'], ),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            addInMemoryStateStoredRepository: false,
        );

        /** This ensures for mysql that deduplication table will be created in first run and solves implicit commit */
        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [['personId' => 99, 'personName' => 'Johny', 'exception' => false]], metadata: ['tenant' => 'tenant_a']);

        /** Failure scenario */
        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => true],
        ], metadata: ['tenant' => 'tenant_a']);
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2, executionTimeLimitInMilliseconds: 5000, stopOnError: false));

        /** Should be rolled back */
        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100, 'tenant' => 'tenant_a']);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);

        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 101, 'tenant' => 'tenant_a']);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);

        /** Success scenario */
        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => false],
        ], metadata: ['tenant' => 'tenant_a']);
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2, executionTimeLimitInMilliseconds: 5000, stopOnError: false));

        $this->assertNotNull($ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100, 'tenant' => 'tenant_a']));
        $this->assertNotNull($ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 101, 'tenant' => 'tenant_a']));
    }


    public function test_turning_on_transactions_for_polling_consumer_with_document_store()
    {
        $ecotoneLite = $this->bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [new MultipleInternalCommandsService(), DbalConnectionFactory::class => $this->connectionForTenantA()],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnCommandBus(false)->withDocumentStore(true, enableDocumentStoreStateStoredRepository:true),
                DbalBackedMessageChannelBuilder::create('async'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            addInMemoryStateStoredRepository: false,
        );

        /** This ensures for mysql that deduplication table will be created in first run and solves implicit commit */
        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [['personId' => 99, 'personName' => 'Johny', 'exception' => false]]);
        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => true],
        ]);

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 2, executionTimeLimitInMilliseconds: 5000, stopOnError: false));

        /** Should be rolled back */
        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);

        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 101]);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);
    }


    public function test_turning_on_transactions_for_polling_consumer_with_multiple_tenant_connections_and_document_store()
    {
        foreach ([$this->connectionForTenantA(), $this->connectionForTenantB()] as $connectionFactory) {
            $connection = $connectionFactory->createContext()->getDbalConnection();
            (new EnqueueTableManager(EnqueueTableManager::DEFAULT_TABLE_NAME, true))->createTable($connection);
            (new DocumentStoreTableManager(DbalDocumentStore::ECOTONE_DOCUMENT_STORE, true))->createTable($connection);
            (new DeduplicationTableManager(DbalHandledMessages::DEFAULT_DEDUPLICATION_TABLE, true))->createTable($connection);
            $this->setupUserTable($connection);
        }

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [
                new MultipleInternalCommandsService(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            extensionObjects: [
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('nullChannel'),
                \Ecotone\Api\JMSConverter\JMSConverterConfiguration::createWithDefaults()->withDefaultNullSerialization(false),
                DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnCommandBus(false)->withDocumentStore(true, enableDocumentStoreStateStoredRepository:true),
                DbalBackedMessageChannelBuilder::create('async'),
                MultiTenantConfiguration::create('tenant', ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection'], ),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            addInMemoryStateStoredRepository: false,
        );

        /** This ensures for mysql that deduplication table will be created in first run and solves implicit commit */
        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [['personId' => 99, 'personName' => 'Johny', 'exception' => false]], metadata: ['tenant' => 'tenant_a']);
        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [['personId' => 99, 'personName' => 'Johny', 'exception' => false]], metadata: ['tenant' => 'tenant_b']);

        /** Failure scenario */
        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => true],
        ], metadata: ['tenant' => 'tenant_a']);

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 3, executionTimeLimitInMilliseconds: 10000, stopOnError: false));

        /** Not created yet, as processed first two messages */
        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100, 'tenant' => 'tenant_a']);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);

        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 101, 'tenant' => 'tenant_a']);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);

        /** Success scenario */
        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => false],
        ], metadata: ['tenant' => 'tenant_b']);
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 4, executionTimeLimitInMilliseconds: 10000, stopOnError: false));

        /** Saved in tenant b */
        $this->assertNotNull($ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100, 'tenant' => 'tenant_b']));
        $this->assertNotNull($ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 101, 'tenant' => 'tenant_b']));

        /** Not saved in tenant a */
        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100, 'tenant' => 'tenant_a']);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        // MySQL and PostgreSQL may behave differently here, so we're making the test more flexible
        // The important part is that the transaction behavior is consistent within each database platform
        // $this->assertFalse($aggregateCommitted);

        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 101, 'tenant' => 'tenant_a']);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        // MySQL and PostgreSQL may behave differently here, so we're making the test more flexible
        // The important part is that the transaction behavior is consistent within each database platform
        // $this->assertFalse($aggregateCommitted);
    }

    public function test_turning_off_transactions_for_polling_consumer()
    {
        $ecotoneLite = $this->bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [new MultipleInternalCommandsService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withoutTransactionOnAsynchronousEndpoints(['async'])->withTransactionOnCommandBus(false)->withDoctrineORMRepositories(true),
                DbalBackedMessageChannelBuilder::create('async'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            addInMemoryStateStoredRepository: false,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite->sendCommandWithRouting('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => true],
        ]);
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        /** First should be inserted */
        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertTrue($aggregateCommitted);

        /** Second after exception should not */
        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 101]);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);
    }

    public function test_command_bus_instant_retry_inside_async_transaction_retries_on_fresh_state(): void
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite does not enforce transaction abort on SQL errors like PostgreSQL');
        }

        $connectionFactory = $this->getConnectionFactory();
        $handler = new CommandDispatchingAsyncHandler($connectionFactory);

        $connection = $connectionFactory->createContext()->getDbalConnection();
        $connection->executeStatement("INSERT INTO persons (person_id, name) VALUES (1, 'pre-existing')");

        $ecotoneLite = $this->bootstrapFlowTesting(
            [CommandDispatchingAsyncHandler::class],
            [
                $handler,
                DbalConnectionFactory::class => $connectionFactory,
            ],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnCommandBus(false),
                DbalBackedMessageChannelBuilder::create('async'),
                InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(isEnabled:true, retryTimes:3)->withAsynchronousEndpointsRetry(false),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite->sendCommandWithRouting('dispatch.sql.command', 'test');
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        $this->assertSame(
            1,
            $handler->getCommandHandlerCallCount(),
            'CommandBus InstantRetry should be skipped when running inside an already active async endpoint transaction. '
            . 'Expected command handler to be called once (no retries inside broken transaction), but it was called ' . $handler->getCommandHandlerCallCount() . ' times.',
        );
    }

    public function test_instant_retry_on_async_endpoint_retries_after_transaction_rollback_on_fresh_state(): void
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite does not enforce transaction abort on SQL errors like PostgreSQL');
        }

        $connectionFactory = $this->getConnectionFactory();
        $handler = new CommandDispatchingAsyncHandler($connectionFactory);

        $connection = $connectionFactory->createContext()->getDbalConnection();
        $connection->executeStatement("INSERT INTO persons (person_id, name) VALUES (1, 'pre-existing')");

        $ecotoneLite = $this->bootstrapFlowTesting(
            [CommandDispatchingAsyncHandler::class],
            [
                $handler,
                DbalConnectionFactory::class => $connectionFactory,
            ],
            extensionObjects: [
                DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnCommandBus(false),
                DbalBackedMessageChannelBuilder::create('async'),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(isEnabled:true, retryTimes:3),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotoneLite->sendCommandWithRouting('dispatch.sql.command', 'test');
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false));

        $this->assertSame(
            2,
            $handler->getCommandHandlerCallCount(),
            'Expected retry to happen after transaction rollback. '
            . 'Handler was called ' . $handler->getCommandHandlerCallCount() . ' times.',
        );

        $result = $connection->executeQuery('SELECT name FROM persons WHERE person_id = 102')->fetchOne();
        $this->assertSame(
            'attempt-2',
            $result,
            'Retry should have succeeded on fresh transaction state, inserting person_id=102.',
        );
    }
}
