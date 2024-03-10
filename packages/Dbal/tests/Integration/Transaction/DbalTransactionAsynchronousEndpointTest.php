<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Dbal\MultiTenant\MultiTenantConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Modelling\AggregateNotFoundException;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\ORM\FailureMode\MultipleInternalCommandsService;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;

/**
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
                        ->withDocumentStore(true, enableDocumentStoreAggregateRepository: true),
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
                        ->withDocumentStore(true, enableDocumentStoreAggregateRepository: true),
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
                        ->withDocumentStore(true, enableDocumentStoreAggregateRepository: true),
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
