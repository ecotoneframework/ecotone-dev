<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\ProjectionHandlersExecutionRoutingTest\{AnAggregate,
    AnEvent,
    Converters,
    ProjectionWithObjectRouting};

/**
 * @internal
 */
class ProjectionHandlersExecutionRoutingTest extends EventSourcingMessagingTestCase
{
    public function test_projection_with_object_routing(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [ProjectionWithObjectRouting::class, AnEvent::class, AnAggregate::class, Converters::class],
            containerOrAvailableServices: [$projection = new ProjectionWithObjectRouting(), new Converters(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ]),
            runForProductionEventStore: true
        );

        $ecotone->sendCommandWithRouting('create', '123');

        self::assertEquals(
            [new AnEvent('123')],
            $projection->events,
            'Projection should receive named event even if object routing is used'
        );
    }
}
