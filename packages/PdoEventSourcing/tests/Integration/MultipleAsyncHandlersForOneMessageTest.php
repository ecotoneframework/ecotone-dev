<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\MultipleAsyncHandlersForOneMessage\ActionCommand;
use Test\Ecotone\EventSourcing\Fixture\MultipleAsyncHandlersForOneMessage\EventConverter;
use Test\Ecotone\EventSourcing\Fixture\MultipleAsyncHandlersForOneMessage\TestAggregate;

/**
 * @internal
 */
final class MultipleAsyncHandlersForOneMessageTest extends EventSourcingMessagingTestCase
{
    public function test_handling_multiple_same_messages(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [TestAggregate::class, EventConverter::class],
            containerOrAvailableServices: [
                new EventConverter(),
                DbalConnectionFactory::class => self::getConnectionFactory(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces(['Test\Ecotone\Modelling\Fixture\MultipleAsyncHandlersForOneMessage'])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults(),
                    EventSourcingConfiguration::createWithDefaults(),
                ]),
            runForProductionEventStore: true
        );

        $ecotone->sendCommand(command: new ActionCommand('123'), metadata: ['call' => 1]);
        $ecotone->sendCommand(command: new ActionCommand('123'), metadata: ['call' => 2]);

        $ecotone->run('testAggregate', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertEquals(1, $ecotone->getAggregate(TestAggregate::class, '123')->counter());

        $ecotone->run('testAggregate', ExecutionPollingMetadata::createWithTestingSetup());
        self::assertEquals(2, $ecotone->getAggregate(TestAggregate::class, '123')->counter());
    }
}
