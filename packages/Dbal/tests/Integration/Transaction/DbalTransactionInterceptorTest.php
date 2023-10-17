<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Transaction;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
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
final class DbalTransactionInterceptorTest extends DbalMessagingTestCase
{
    public function test_turning_on_transactions_for_polling_consumer()
    {
        $this->setupUserTable();

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

        /** First should be rolled back */
        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 100]);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);

        /** Second after exception should not */
        $aggregateCommitted = true;
        try {
            $ecotoneLite->sendQueryWithRouting('person.getName', metadata: ['aggregate.id' => 101]);
        } catch (AggregateNotFoundException) {
            $aggregateCommitted = false;
        }
        $this->assertFalse($aggregateCommitted);
    }

    public function test_turning_off_transactions_for_polling_consumer()
    {
        $this->setupUserTable();

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
