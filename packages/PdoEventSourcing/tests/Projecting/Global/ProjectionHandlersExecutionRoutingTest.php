<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\GlobalProjection;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\ProjectionHandlersExecutionRoutingTest\AnAggregate;
use Test\Ecotone\EventSourcing\Fixture\ProjectionHandlersExecutionRoutingTest\AnEvent;
use Test\Ecotone\EventSourcing\Fixture\ProjectionHandlersExecutionRoutingTest\Converters;

/**
 * @internal
 */
class ProjectionHandlersExecutionRoutingTest extends EventSourcingMessagingTestCase
{
    public function test_projection_with_object_routing(): void
    {
        $projection = $this->getProjectionWithObjectRouting();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [\get_class($projection), AnAggregate::class, AnEvent::class, Converters::class],
            containerOrAvailableServices: [$projection, new Converters(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRoutingKey('create', '123');

        self::assertEquals(
            [new AnEvent('123')],
            $projection->events,
            'Projection should receive named event even if object routing is used'
        );
    }

    public function test_projection_with_regex_routing(): void
    {
        $projection = $this->getProjectionWithRegexRouting();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [\get_class($projection), AnAggregate::class, AnEvent::class, Converters::class],
            containerOrAvailableServices: [$projection, new Converters(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRoutingKey('create', '123');

        self::assertEquals(
            [
                ['id' => '123'],
            ],
            $projection->events,
            'Projection should receive named event with regex routing'
        );
    }

    public function test_projection_with_multiple_handlers_for_same_event(): void
    {
        $this->markTestSkipped(
            'The new GlobalProjection system does not support multiple handlers for the same event. ' .
            'The RouterProcessor is configured with single route only mode.'
        );
    }

    private function getProjectionWithObjectRouting(): object
    {
        return new #[GlobalProjection('projection_with_object_routing'), FromStream(AnAggregate::STREAM_NAME)] class {
            public array $events = [];

            #[EventHandler]
            public function onEvent(AnEvent $event): void
            {
                $this->events[] = $event;
            }
        };
    }

    private function getProjectionWithRegexRouting(): object
    {
        return new #[GlobalProjection('projection_with_regex_routing'), FromStream(AnAggregate::STREAM_NAME)] class {
            public array $events = [];

            #[EventHandler('test.*')]
            public function onEvent(array $event): void
            {
                $this->events[] = $event;
            }
        };
    }
}

