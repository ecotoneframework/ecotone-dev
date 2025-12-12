<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Integration;

use Ecotone\EventSourcing\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Attribute\Converter;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\DatePoint;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\NamedEvent;
use Ecotone\Modelling\Event;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Projecting\Attribute\Projection;
use Ecotone\Test\LicenceTesting;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * @internal
 */
class DeletedEventClassInStreamTest extends EventSourcingMessagingTestCase
{
    public function test_event_sourcing_with_deleted_event_class_in_stream(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            [ANamedEvent::class, AnAggregate::class, AProjection::class],
            [
                DbalConnectionFactory::class => $this->getConnectionFactory(),
                AProjection::class => $projection = new AProjection(),
            ],
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE])),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE
        )->sendCommandWithRoutingKey('create', ['id' => 'aggregate-1']);

        // I append an unknown event to the same stream
        $ecotone->getGateway(EventStore::class)
            ->appendTo('a-stream', [
                Event::createWithType(
                    'an-unknown-event', // This could be a named event that has been deleted from codebase
                    [],
                    [
                        MessageHeaders::EVENT_AGGREGATE_ID => 'aggregate-1',
                        MessageHeaders::EVENT_AGGREGATE_TYPE => AnAggregate::class,
                        MessageHeaders::EVENT_AGGREGATE_VERSION => 2,
                        MessageHeaders::TIMESTAMP => (new DatePoint())->getTimestamp(),
                    ]
                ),
            ]);

        // I verify that I can still trigger projections without issues
        $ecotone->triggerProjection('a-projection');
        $this->assertCount(1, $projection->events);
    }
}

#[NamedEvent('a-named-event')]
class ANamedEvent
{
    public function __construct(public string $id)
    {
    }

    #[Converter]
    public static function toArray(self $event): array
    {
        return ['id' => $event->id];
    }

    #[Converter]
    public static function fromArray(array $data): self
    {
        return new self($data['id']);
    }
}

#[EventSourcingAggregate, Stream('a-stream')]
class AnAggregate
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $id;

    #[CommandHandler('create')]
    public static function create(array $command): array
    {
        return [new ANamedEvent($command['id'])];
    }

    #[EventSourcingHandler]
    public function onAnotherNamedEvent(ANamedEvent $event): void
    {
        $this->id = $event->id;
    }
}

#[Projection('a-projection'), FromStream('a-stream')]
class AProjection
{
    public array $events = [];

    #[EventHandler]
    public function onNamedEvent(ANamedEvent $event): void
    {
        $this->events[] = $event;
    }
}
