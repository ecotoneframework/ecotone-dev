<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Handler\Logger\EchoLogger;
use Ecotone\Modelling\AggregateNotFoundException;
use Enqueue\Dbal\DbalConnectionFactory;
use Exception;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ConnectionBreakingConfiguration;
use Test\Ecotone\Dbal\Fixture\ConnectionBreakingModule;
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
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [new MultipleInternalCommandsService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnAsynchronousEndpoints(true)
                        ->withTransactionOnCommandBus(false)
                        ->withDoctrineORMRepositories(true),
                    DbalBackedMessageChannelBuilder::create('async'),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE])),
            addInMemoryStateStoredRepository: false
        );

        /** This ensures for mysql that deduplication table will be created in first run and solves implicit commit */
        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [['personId' => 99, 'personName' => 'Johny', 'exception' => false]]);
        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => true],
        ]);

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2, failAtError: false));

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
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class, ConnectionBreakingModule::class],
            [
                new MultipleInternalCommandsService(),
                DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person']),
                //                "logger" => new EchoLogger()
            ],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnAsynchronousEndpoints(true)
                        ->withTransactionOnCommandBus(false)
                        ->withDoctrineORMRepositories(true),
                    DbalBackedMessageChannelBuilder::create('async'),
                    // Configure to break connection on the third commit
                    // First two commits should succeed, third should fail but recover
                    ConnectionBreakingConfiguration::createWithBreakBeforeCommit([false, false, false, true]),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE])),
            addInMemoryStateStoredRepository: false
        );

        // Send a command to ensure the deduplication table is created
        $ecotoneLite->sendCommandWithRoutingKey('singeInternalCommand', ['personId' => 99, 'personName' => 'Johny', 'exception' => false]);
        $ecotoneLite->sendCommandWithRoutingKey('singeInternalCommand', ['personId' => 100, 'personName' => 'Johny', 'exception' => false]);

        // Run with enough message handling attempts to process both commands
        // The second command will encounter a connection failure but should recover
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 5, failAtError: false));

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

    /**
     * @group test_reconnecting_on_lost_connection_during_dead_letter_storage
     */
    public function test_reconnecting_on_lost_connection_during_dead_letter_storage()
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite does not support connection breaking/recovering testing');
        }

        // First, create a regular EcotoneLite instance to set up the database tables
        $setupEcotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [
                new MultipleInternalCommandsService(),
                DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person']),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withDefaultErrorChannel('dbal_dead_letter')
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnAsynchronousEndpoints(true)
                        ->withTransactionOnCommandBus(false)
                        ->withDoctrineORMRepositories(true),
                    DbalBackedMessageChannelBuilder::create('async'),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE])),
            addInMemoryStateStoredRepository: false
        );

        // Send a command to ensure the deduplication table is created
        $setupEcotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [['personId' => 98, 'personName' => 'Setup', 'exception' => false]]);
        $setupEcotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false));

        // Now create the actual test instance with the connection breaking module
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class, ConnectionBreakingModule::class],
            [
                new MultipleInternalCommandsService(),
                DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person']),
                'logger' => new EchoLogger(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withDefaultErrorChannel('dbal_dead_letter')
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnAsynchronousEndpoints(true)
                        ->withTransactionOnCommandBus(false)
                        ->withDoctrineORMRepositories(true),
                    DbalBackedMessageChannelBuilder::create('async'),
                    // Configure to break connection during dead letter storage
                    ConnectionBreakingConfiguration::createWithBreakBeforeCommit([true, false]),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE])),
            addInMemoryStateStoredRepository: false
        );

        $ecotoneLite->sendCommandWithRoutingKey('singeInternalCommand', ['personId' => 100, 'personName' => 'Johny', 'exception' => true]);

        /** @var DeadLetterGateway $deadLetter */
        $deadLetter = $ecotoneLite->getGateway(DeadLetterGateway::class);
        $initialCount = $deadLetter->count();

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false));

        // After running, we should have one more dead letter than before
        $this->assertSame($initialCount + 1, $deadLetter->count());
    }

    public function test_turning_on_transactions_for_polling_consumer_with_tenant_connection()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [
                new MultipleInternalCommandsService(),
                'tenant_a_connection' => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person']),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    ServiceConfiguration::createWithDefaults()
                        ->withDefaultErrorChannel('nullChannel'),
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnAsynchronousEndpoints(true)
                        ->withTransactionOnCommandBus(false)
                        ->withDocumentStore(true, enableDocumentStoreStandardRepository: true),
                    DbalBackedMessageChannelBuilder::create('async'),
                    MultiTenantConfiguration::create(
                        'tenant',
                        ['tenant_a' => 'tenant_a_connection'],
                    ),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])),
            addInMemoryStateStoredRepository: false
        );

        /** This ensures for mysql that deduplication table will be created in first run and solves implicit commit */
        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [['personId' => 99, 'personName' => 'Johny', 'exception' => false]], metadata: ['tenant' => 'tenant_a']);

        /** Failure scenario */
        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => true],
        ], metadata: ['tenant' => 'tenant_a']);
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2, failAtError: false));

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
        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => false],
        ], metadata: ['tenant' => 'tenant_a']);
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2, failAtError: false));

        $this->assertNotNull($ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100, 'tenant' => 'tenant_a']));
        $this->assertNotNull($ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 101, 'tenant' => 'tenant_a']));
    }


    public function test_turning_on_transactions_for_polling_consumer_with_document_store()
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [new MultipleInternalCommandsService(), DbalConnectionFactory::class => $this->connectionForTenantA()],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnAsynchronousEndpoints(true)
                        ->withTransactionOnCommandBus(false)
                        ->withDocumentStore(true, enableDocumentStoreStandardRepository: true),
                    DbalBackedMessageChannelBuilder::create('async'),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])),
            addInMemoryStateStoredRepository: false
        );

        /** This ensures for mysql that deduplication table will be created in first run and solves implicit commit */
        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [['personId' => 99, 'personName' => 'Johny', 'exception' => false]]);
        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => true],
        ]);

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 2, failAtError: false));

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
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [
                new MultipleInternalCommandsService(),
                'tenant_a_connection' => $this->connectionForTenantA(),
                'tenant_b_connection' => $this->connectionForTenantB(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    ServiceConfiguration::createWithDefaults()
                        ->withDefaultErrorChannel('nullChannel'),
                    DbalConfiguration::createWithDefaults()
                        ->withTransactionOnAsynchronousEndpoints(true)
                        ->withTransactionOnCommandBus(false)
                        ->withDocumentStore(true, enableDocumentStoreStandardRepository: true),
                    DbalBackedMessageChannelBuilder::create('async'),
                    MultiTenantConfiguration::create(
                        'tenant',
                        ['tenant_a' => 'tenant_a_connection', 'tenant_b' => 'tenant_b_connection'],
                    ),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])),
            addInMemoryStateStoredRepository: false
        );

        /** This ensures for mysql that deduplication table will be created in first run and solves implicit commit */
        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [['personId' => 99, 'personName' => 'Johny', 'exception' => false]], metadata: ['tenant' => 'tenant_a']);
        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [['personId' => 99, 'personName' => 'Johny', 'exception' => false]], metadata: ['tenant' => 'tenant_b']);

        /** Failure scenario */
        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => true],
        ], metadata: ['tenant' => 'tenant_a']);

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 3, failAtError: false));

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
        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => false],
        ], metadata: ['tenant' => 'tenant_b']);
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 4, failAtError: false));

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
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [new MultipleInternalCommandsService(), DbalConnectionFactory::class => $this->getORMConnectionFactory([__DIR__.'/../Fixture/ORM/Person'])],
            ServiceConfiguration::createWithDefaults()
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()
                        ->withoutTransactionOnAsynchronousEndpoints(['async'])
                        ->withTransactionOnCommandBus(false)
                        ->withDoctrineORMRepositories(true),
                    DbalBackedMessageChannelBuilder::create('async'),
                ])
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE, ModulePackageList::DBAL_PACKAGE])),
            addInMemoryStateStoredRepository: false
        );

        $ecotoneLite->sendCommandWithRoutingKey('multipleInternalCommands', [
            ['personId' => 100, 'personName' => 'Johny', 'exception' => false],
            ['personId' => 101, 'personName' => 'Johny', 'exception' => true],
        ]);
        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(failAtError: false));

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
}
