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
use Test\Ecotone\Dbal\DbalMessagingTestCase;
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

    public function deduplication_table_if_rolled_back_is_handled_correctly_in_next_run()
    {
        if (! method_exists(Connection::class, 'getNativeConnection')) {
            $this->markTestSkipped('Dbal version >= 3.0');
        }

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [new MultipleInternalCommandsService(), DbalConnectionFactory::class => $this->prepareFailingConnection(connectionFailuresOnCommit: [false, true]),
                //                'logger' => new EchoLogger()
            ],
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
        ]);

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 5, failAtError: false));

        /** Should be rolled back */
        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);
    }

    public function test_reconnecting_on_lost_connection_during_commit()
    {
        if (! method_exists(Connection::class, 'getNativeConnection')) {
            $this->markTestSkipped('Dbal version >= 3.0');
        }

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [new MultipleInternalCommandsService(), DbalConnectionFactory::class => $this->prepareFailingConnection(connectionFailuresOnCommit: [false, false, true]),
                //                "logger" => new EchoLogger()
            ],
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
        $ecotoneLite->sendCommandWithRoutingKey('singeInternalCommand', ['personId' => 99, 'personName' => 'Johny', 'exception' => false]);
        $ecotoneLite->sendCommandWithRoutingKey('singeInternalCommand', ['personId' => 100, 'personName' => 'Johny', 'exception' => false]);

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 5, failAtError: false));

        $this->assertNotNull(
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100])
        );
    }

    public function test_reconnecting_on_lost_connection_during_message_acknowledge()
    {
        if (! method_exists(Connection::class, 'getNativeConnection')) {
            $this->markTestSkipped('Dbal version >= 3.0');
        }

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [new MultipleInternalCommandsService(), DbalConnectionFactory::class => $this->prepareFailingConnection(connectionFailureOnMessageAcknowledge: [true, false]),
                //                "logger" => new EchoLogger()
            ],
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

        $ecotoneLite->sendCommandWithRoutingKey('singeInternalCommand', ['personId' => 99, 'personName' => 'Johny', 'exception' => false], metadata: ['publish_event' => false]);

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: true));

        $this->assertNull($ecotoneLite->getMessageChannel('async')->receiveWithTimeout(100));
    }

    public function test_reconnecting_on_lost_connection_during_dead_letter_storage()
    {
        if (! method_exists(Connection::class, 'getNativeConnection')) {
            $this->markTestSkipped('Dbal version >= 3.0');
        }

        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [Person::class, MultipleInternalCommandsService::class],
            [new MultipleInternalCommandsService(), DbalConnectionFactory::class => $this->prepareFailingConnection(connectionFailureOnStoreInDeadLetter: [true, false]),
                //                'logger' => new EchoLogger()
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

        $ecotoneLite->sendCommandWithRoutingKey('singeInternalCommand', ['personId' => 100, 'personName' => 'Johny', 'exception' => true]);

        /** @var DeadLetterGateway $deadLetter */
        $deadLetter = $ecotoneLite->getGateway(DeadLetterGateway::class);
        $this->assertSame(0, $deadLetter->count());

        $ecotoneLite->run('async', ExecutionPollingMetadata::createWithTestingSetup(amountOfMessagesToHandle: 1, failAtError: false));

        $this->assertSame(1, $deadLetter->count());
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
        $this->assertFalse($aggregateCommitted);

        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 101, 'tenant' => 'tenant_a']);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);
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
