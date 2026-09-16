<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Global;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;

use function get_class;

use InvalidArgumentException;
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
            classesToResolve: [get_class($projection), AnAggregate::class, AnEvent::class, Converters::class],
            containerOrAvailableServices: [$projection, new Converters(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('create', '123');

        self::assertEquals(
            [new AnEvent('123')],
            $projection->events,
            'Projection should receive named event even if object routing is used'
        );
    }

    public function test_projection_with_glob_pattern_throws_exception(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("glob pattern 'test.*' which is not allowed");

        $projection = $this->getProjectionWithRegexRouting();

        EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [get_class($projection), AnAggregate::class, AnEvent::class, Converters::class],
            containerOrAvailableServices: [$projection, new Converters(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    public function test_projection_with_multiple_handlers_for_different_events(): void
    {
        $projection = $this->getProjectionWithMultipleHandlers();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [get_class($projection), AnAggregate::class, AnEvent::class, Converters::class],
            containerOrAvailableServices: [$projection, new Converters(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('create', '123');

        self::assertEquals(
            [new AnEvent('123')],
            $projection->events,
            'Projection should route event to the correct handler based on type'
        );
    }

    public function test_projection_having_same_event_registered_differently(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $projection = new #[Projection('projection_with_multiple_handlers'), FromStream(AnAggregate::STREAM_NAME)] class {
            public array $events = [];

            #[EventHandler]
            public function onAnEvent(AnEvent $event): void
            {
                $this->events[] = $event;
            }

            #[EventHandler('test.an_event')]
            public function onOtherEvent(array $event): void
            {
                $this->events[] = $event;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [get_class($projection), AnAggregate::class, AnEvent::class, Converters::class],
            containerOrAvailableServices: [$projection, new Converters(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('create', '123');

        self::assertEquals(
            [new AnEvent('123'), ['id' => '123']],
            $projection->events,
            'Projection should route event to the correct handler based on type'
        );
    }

    private function getProjectionWithObjectRouting(): object
    {
        return new #[Projection('projection_with_object_routing'), FromStream(AnAggregate::STREAM_NAME)] class {
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
        return new #[Projection('projection_with_regex_routing'), FromStream(AnAggregate::STREAM_NAME)] class {
            public array $events = [];

            #[EventHandler('test.*')]
            public function onEvent(array $event): void
            {
                $this->events[] = $event;
            }
        };
    }

    private function getProjectionWithMultipleHandlers(): object
    {
        return new #[Projection('projection_with_multiple_handlers'), FromStream(AnAggregate::STREAM_NAME)] class {
            public array $events = [];

            #[EventHandler]
            public function onAnEvent(AnEvent $event): void
            {
                $this->events[] = $event;
            }

            #[EventHandler('other.event')]
            public function onOtherEvent(array $event): void
            {
                $this->events[] = $event;
            }
        };
    }
}
