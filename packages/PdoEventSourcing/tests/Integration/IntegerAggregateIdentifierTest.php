<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class IntegerAggregateIdentifierTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'in-memory-without-event-sourcing-module' => ['in-memory-without-event-sourcing-module'];
        yield 'dbal' => ['dbal'];
    }

    #[DataProvider('implementations')]
    public function test_an_integer_identifier_is_recorded_as_its_string_form(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $ecotone->sendCommand(new OpenCounterWithIntegerId(42));

        $recordedAggregateIds = array_map(
            static fn ($event) => $event->getMetadata()[MessageHeaders::EVENT_AGGREGATE_ID],
            $ecotone->getGateway(EventStore::class)->load(self::STREAM),
        );
        self::assertSame(['42'], $recordedAggregateIds);
    }

    #[DataProvider('implementations')]
    public function test_an_integer_identifier_is_published_with_the_event_as_its_string_form(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $ecotone->sendCommand(new OpenCounterWithIntegerId(42));

        self::assertSame('42', $ecotone->popRecordedEventHeaders()[0]->get(MessageHeaders::EVENT_AGGREGATE_ID));
    }

    #[DataProvider('implementations')]
    public function test_an_aggregate_with_an_integer_identifier_is_reconstituted_from_its_events(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);

        $ecotone->sendCommand(new OpenCounterWithIntegerId(42));
        $ecotone->sendCommand(new IncrementCounterWithIntegerId(42));
        $ecotone->sendCommand(new IncrementCounterWithIntegerId(42));

        self::assertSame(2, $ecotone->sendQueryWithRouting('counterWithIntegerId.count', metadata: ['aggregate.id' => 42]));
        self::assertCount(3, $ecotone->getGateway(EventStore::class)->loadAggregateEvents(self::STREAM, 'counter_with_integer_id', '42'));
    }

    #[DataProvider('implementations')]
    public function test_an_aggregate_whose_history_was_recorded_with_an_integer_identifier_still_loads(string $implementation): void
    {
        $ecotone = $this->bootstrap($implementation);
        $ecotone->getGateway(EventStore::class)->appendTo(self::STREAM, [
            Event::create(new CounterWithIntegerIdOpened(42), [
                MessageHeaders::EVENT_AGGREGATE_TYPE => 'counter_with_integer_id',
                MessageHeaders::EVENT_AGGREGATE_ID => 42,
                MessageHeaders::EVENT_AGGREGATE_VERSION => 1,
            ]),
        ]);

        $ecotone->sendCommand(new IncrementCounterWithIntegerId(42));

        self::assertSame(1, $ecotone->sendQueryWithRouting('counterWithIntegerId.count', metadata: ['aggregate.id' => 42]));
    }

    private function bootstrap(string $implementation): FlowTestSupport
    {
        $converter = new class () {
            #[Converter]
            public function fromOpened(CounterWithIntegerIdOpened $event): array
            {
                return ['counterId' => $event->counterId];
            }

            #[Converter]
            public function toOpened(array $event): CounterWithIntegerIdOpened
            {
                return new CounterWithIntegerIdOpened($event['counterId']);
            }

            #[Converter]
            public function fromIncremented(CounterWithIntegerIdIncremented $event): array
            {
                return ['counterId' => $event->counterId];
            }

            #[Converter]
            public function toIncremented(array $event): CounterWithIntegerIdIncremented
            {
                return new CounterWithIntegerIdIncremented($event['counterId']);
            }
        };

        if ($implementation === 'in-memory-without-event-sourcing-module') {
            return EcotoneLite::bootstrapFlowTesting(
                classesToResolve: [CounterWithIntegerId::class, $converter::class],
                containerOrAvailableServices: [$converter],
            );
        }

        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [CounterWithIntegerId::class, $converter::class],
            containerOrAvailableServices: [$converter, DbalConnectionFactory::class => self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    match ($implementation) {
                        'in-memory' => EventSourcingConfiguration::createInMemory(),
                        'dbal' => EventSourcingConfiguration::createWithDefaults(),
                    },
                ]),
            runForProductionEventStore: true,
        );
        $ecotone->setupDatabaseTables();

        return $ecotone;
    }
}

final readonly class OpenCounterWithIntegerId
{
    public function __construct(public int $counterId)
    {
    }
}

final readonly class IncrementCounterWithIntegerId
{
    public function __construct(public int $counterId)
    {
    }
}

final readonly class CounterWithIntegerIdOpened
{
    public function __construct(public int $counterId)
    {
    }
}

final readonly class CounterWithIntegerIdIncremented
{
    public function __construct(public int $counterId)
    {
    }
}

#[EventSourcingAggregate]
#[AggregateType('counter_with_integer_id')]
final class CounterWithIntegerId
{
    use WithAggregateVersioning;

    #[Identifier]
    private int $counterId;

    private int $count = 0;

    #[CommandHandler]
    public static function open(OpenCounterWithIntegerId $command): array
    {
        return [new CounterWithIntegerIdOpened($command->counterId)];
    }

    #[CommandHandler]
    public function increment(IncrementCounterWithIntegerId $command): array
    {
        return [new CounterWithIntegerIdIncremented($command->counterId)];
    }

    #[QueryHandler('counterWithIntegerId.count')]
    public function count(): int
    {
        return $this->count;
    }

    #[EventSourcingHandler]
    public function applyOpened(CounterWithIntegerIdOpened $event): void
    {
        $this->counterId = $event->counterId;
    }

    #[EventSourcingHandler]
    public function applyIncremented(CounterWithIntegerIdIncremented $event): void
    {
        $this->count++;
    }
}
