<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class UndeclaredStreamRefusalTest extends EventSourcingMessagingTestCase
{
    public function test_a_projection_reading_a_stream_nothing_declares_is_refused_at_bootstrap(): void
    {
        $projection = new #[Projection('undeclared_stream_reader'), FromStream('nobody_declares_this_stream')] class () {
            #[EventHandler]
            public function on(ParcelShippedForUndeclaredStreamTest $event): void
            {
            }
        };

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            "Projection `undeclared_stream_reader` reads event stream `nobody_declares_this_stream` through #[FromStream('nobody_declares_this_stream')], but nothing declares that stream, so no migration will ever create its table: ecotone:migration:database:setup creates the tables of the default stream and of every stream a #[Stream] attribute declares. "
            . "Declare it with #[Stream('nobody_declares_this_stream')] on the class that owns its events - an event-sourced aggregate, the projection that emits into it, or any class Ecotone scans - and create its table with ecotone:migration:database:setup --initialize (in an EcotoneLite flow test, \$ecotone->setupDatabaseTables()). "
            . 'Or use a declared stream: ecotone_event_stream.'
        );

        $this->bootstrapOnDatabase([$projection]);
    }

    public function test_appending_to_a_stream_nothing_declares_is_refused_naming_the_stream_to_declare(): void
    {
        $ecotone = $this->bootstrapOnDatabase([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Event stream `nobody_declares_this_stream` is appended to, but nothing declares that stream, so no migration will ever create its table: ecotone:migration:database:setup creates the tables of the default stream and of every stream a #[Stream] attribute declares. '
            . "Declare it with #[Stream('nobody_declares_this_stream')] on the class that owns its events - an event-sourced aggregate, the projection that emits into it, or any class Ecotone scans - and create its table with ecotone:migration:database:setup --initialize (in an EcotoneLite flow test, \$ecotone->setupDatabaseTables()). "
            . 'Or use a declared stream: ecotone_event_stream.'
        );

        $ecotone->getGateway(EventStore::class)->appendTo('nobody_declares_this_stream', [new ParcelShippedForUndeclaredStreamTest('p-1')]);
    }

    public function test_a_projection_reading_a_stream_a_class_declares_boots(): void
    {
        $owner = new #[Stream('declared_parcels')] class () {
        };
        $projection = new #[Projection('declared_stream_reader'), FromStream('declared_parcels')] class () {
            #[EventHandler]
            public function on(ParcelShippedForUndeclaredStreamTest $event): void
            {
            }
        };

        $ecotone = $this->bootstrapOnDatabase([$owner, $projection]);

        self::assertTrue($ecotone->getGateway(EventStore::class)->hasStream('declared_parcels'));
    }

    public function test_an_in_memory_event_store_boots_a_projection_reading_a_stream_nothing_declares(): void
    {
        $projection = new #[Projection('undeclared_in_memory_reader'), FromStream('nobody_declares_this_stream')] class () {
            #[EventHandler]
            public function on(ParcelShippedForUndeclaredStreamTest $event): void
            {
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$projection::class],
            containerOrAvailableServices: [$projection],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([EventSourcingConfiguration::createInMemory()]),
        );

        self::assertFalse($ecotone->getGateway(EventStore::class)->hasStream('nobody_declares_this_stream'));
    }

    private function bootstrapOnDatabase(array $classes): FlowTestSupport
    {
        $classes[] = new class () {
            #[Converter]
            public function fromParcelShipped(ParcelShippedForUndeclaredStreamTest $event): array
            {
                return ['parcelId' => $event->parcelId];
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: array_map(static fn (object $class) => $class::class, $classes),
            containerOrAvailableServices: [...$classes, DbalConnectionFactory::class => self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnabledModulePackageNames([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([EventSourcingConfiguration::createWithDefaults()]),
        );
    }
}

final readonly class ParcelShippedForUndeclaredStreamTest
{
    public function __construct(public string $parcelId)
    {
    }
}
