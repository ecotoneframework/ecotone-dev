<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Partitioned;

use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;

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
    public function test_partitioned_projection_with_object_routing(): void
    {
        $projection = $this->getProjectionWithObjectRouting();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [get_class($projection), AnAggregate::class, AnEvent::class, Converters::class],
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
            'Partitioned projection should receive named event even if object routing is used'
        );
    }

    public function test_partitioned_projection_with_regex_routing(): void
    {
        $projection = $this->getProjectionWithRegexRouting();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [get_class($projection), AnAggregate::class, AnEvent::class, Converters::class],
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
            'Partitioned projection should receive named event with regex routing'
        );
    }

    public function test_partitioned_projection_with_multiple_handlers_for_different_events(): void
    {
        $projection = $this->getProjectionWithMultipleHandlers();

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [get_class($projection), AnAggregate::class, AnEvent::class, Converters::class],
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
            'Partitioned projection should route event to the correct handler based on type'
        );
    }

    public function test_partitioned_projection_having_same_event_registered_differently(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $projection = new #[ProjectionV2('partitioned_projection_with_multiple_handlers'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: AnAggregate::STREAM_NAME, aggregateType: AnAggregate::class)] class {
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
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRoutingKey('create', '123');

        self::assertEquals(
            [new AnEvent('123'), ['id' => '123']],
            $projection->events,
            'Partitioned projection should route event to the correct handler based on type'
        );
    }

    private function getProjectionWithObjectRouting(): object
    {
        return new #[ProjectionV2('partitioned_projection_with_object_routing'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: AnAggregate::STREAM_NAME, aggregateType: AnAggregate::class)] class {
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
        return new #[ProjectionV2('partitioned_projection_with_regex_routing'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: AnAggregate::STREAM_NAME, aggregateType: AnAggregate::class)] class {
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
        return new #[ProjectionV2('partitioned_projection_with_multiple_handlers'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: AnAggregate::STREAM_NAME, aggregateType: AnAggregate::class)] class {
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
