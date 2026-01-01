<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Partitioned;

use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;

use function get_class;

use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\EventNameFiltering\Converters;
use Test\Ecotone\EventSourcing\Fixture\EventNameFiltering\FirstEvent;
use Test\Ecotone\EventSourcing\Fixture\EventNameFiltering\MultiEventAggregate;
use Test\Ecotone\EventSourcing\Fixture\EventNameFiltering\SecondEvent;

/**
 * Tests for event name filtering optimization in partitioned projections.
 *
 * @internal
 */
class EventNameFilteringTest extends EventSourcingMessagingTestCase
{
    public function test_partitioned_projection_with_explicit_event_names_for_all_events(): void
    {
        $projection = $this->getProjectionHandlingAllEvents();

        $ecotone = $this->bootstrapEcotone([$projection]);

        $ecotone->sendCommandWithRoutingKey('createMultiEvent', '123');

        self::assertEquals(
            ['first_event:123', 'second_event:123'],
            $projection->events,
            'Projection with explicit handlers for all events should receive all events'
        );
    }

    public function test_partitioned_projection_filters_to_only_handled_events(): void
    {
        $projection = $this->getProjectionHandlingOnlyOneEvent();

        $ecotone = $this->bootstrapEcotone([$projection]);

        $ecotone->sendCommandWithRoutingKey('createMultiEvent', '123');

        self::assertEquals(
            ['first_event:123'],
            $projection->events,
            'Projection handling only one event type should only receive that event type'
        );
    }

    public function test_partitioned_projection_with_catch_all_pattern(): void
    {
        $projection = $this->getProjectionWithCatchAllPattern();

        $ecotone = $this->bootstrapEcotone([$projection]);

        $ecotone->sendCommandWithRoutingKey('createMultiEvent', '123');

        self::assertEquals(
            [['id' => '123', 'type' => 'first'], ['id' => '123', 'type' => 'second']],
            $projection->events,
            'Projection with "*" pattern should receive all events'
        );
    }

    public function test_partitioned_projection_with_object_type_receives_all_events(): void
    {
        $projection = $this->getProjectionWithObjectType();

        $ecotone = $this->bootstrapEcotone([$projection]);

        $ecotone->sendCommandWithRoutingKey('createMultiEvent', '123');

        self::assertCount(
            2,
            $projection->events,
            'Projection with object type parameter should receive all events'
        );
    }

    public function test_partitioned_projection_resolves_event_name_from_named_event_attribute(): void
    {
        $projection = $this->getProjectionWithClassTypeResolvingToNamedEvent();

        $ecotone = $this->bootstrapEcotone([$projection]);

        $ecotone->sendCommandWithRoutingKey('createMultiEvent', '123');

        self::assertEquals(
            ['first_event:123'],
            $projection->events,
            'Projection with class type should resolve event name from #[NamedEvent] attribute'
        );
    }

    public function test_partitioned_projection_with_union_type_for_multiple_events(): void
    {
        $projection = $this->getProjectionWithUnionType();

        $ecotone = $this->bootstrapEcotone([$projection]);

        $ecotone->sendCommandWithRoutingKey('createMultiEvent', '123');

        self::assertEquals(
            ['union:123', 'union:123'],
            $projection->events,
            'Projection with union type should receive all specified event types'
        );
    }

    public function test_partitioned_projection_with_glob_pattern_throws_exception(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("glob pattern 'order.*' which is not allowed");

        $projection = $this->getProjectionWithGlobPattern();

        $this->bootstrapEcotone([$projection]);
    }

    private function bootstrapEcotone(array $projections)
    {
        $classes = array_map(fn ($p) => get_class($p), $projections);

        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: array_merge($classes, [MultiEventAggregate::class, FirstEvent::class, SecondEvent::class, Converters::class]),
            containerOrAvailableServices: array_merge($projections, [new Converters(), DbalConnectionFactory::class => $this->getConnectionFactory()]),
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function getProjectionHandlingAllEvents(): object
    {
        return new #[ProjectionV2('projection_all_events'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: MultiEventAggregate::STREAM_NAME, aggregateType: MultiEventAggregate::class)] class {
            public array $events = [];

            #[EventHandler]
            public function onFirstEvent(FirstEvent $event): void
            {
                $this->events[] = 'first_event:' . $event->id;
            }

            #[EventHandler]
            public function onSecondEvent(SecondEvent $event): void
            {
                $this->events[] = 'second_event:' . $event->id;
            }
        };
    }

    private function getProjectionHandlingOnlyOneEvent(): object
    {
        return new #[ProjectionV2('projection_one_event'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: MultiEventAggregate::STREAM_NAME, aggregateType: MultiEventAggregate::class)] class {
            public array $events = [];

            #[EventHandler]
            public function onFirstEvent(FirstEvent $event): void
            {
                $this->events[] = 'first_event:' . $event->id;
            }
        };
    }

    private function getProjectionWithCatchAllPattern(): object
    {
        return new #[ProjectionV2('projection_catch_all'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: MultiEventAggregate::STREAM_NAME, aggregateType: MultiEventAggregate::class)] class {
            public array $events = [];

            #[EventHandler('*')]
            public function onAnyEvent(array $event): void
            {
                $this->events[] = $event;
            }
        };
    }

    private function getProjectionWithObjectType(): object
    {
        return new #[ProjectionV2('projection_object_type'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: MultiEventAggregate::STREAM_NAME, aggregateType: MultiEventAggregate::class)] class {
            public array $events = [];

            #[EventHandler]
            public function onAnyEvent(object $event): void
            {
                $this->events[] = $event;
            }
        };
    }

    private function getProjectionWithClassTypeResolvingToNamedEvent(): object
    {
        return new #[ProjectionV2('projection_class_type'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: MultiEventAggregate::STREAM_NAME, aggregateType: MultiEventAggregate::class)] class {
            public array $events = [];

            #[EventHandler]
            public function onFirstEvent(FirstEvent $event): void
            {
                $this->events[] = 'first_event:' . $event->id;
            }
        };
    }

    private function getProjectionWithUnionType(): object
    {
        return new #[ProjectionV2('projection_union_type'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: MultiEventAggregate::STREAM_NAME, aggregateType: MultiEventAggregate::class)] class {
            public array $events = [];

            #[EventHandler]
            public function onEvent(FirstEvent|SecondEvent $event): void
            {
                $this->events[] = 'union:' . $event->id;
            }
        };
    }

    private function getProjectionWithGlobPattern(): object
    {
        return new #[ProjectionV2('projection_glob_pattern'), Partitioned(MessageHeaders::EVENT_AGGREGATE_ID), FromStream(stream: MultiEventAggregate::STREAM_NAME, aggregateType: MultiEventAggregate::class)] class {
            public array $events = [];

            #[EventHandler('order.*')]
            public function onOrderEvent(array $event): void
            {
                $this->events[] = $event;
            }
        };
    }
}
