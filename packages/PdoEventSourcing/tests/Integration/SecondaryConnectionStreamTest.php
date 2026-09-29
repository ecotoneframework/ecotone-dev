<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\SecondaryConnectionStream\CancelSecondaryOrder;
use Test\Ecotone\EventSourcing\Fixture\SecondaryConnectionStream\PlaceSecondaryOrder;
use Test\Ecotone\EventSourcing\Fixture\SecondaryConnectionStream\SecondaryOrder;
use Test\Ecotone\EventSourcing\Fixture\SecondaryConnectionStream\SecondaryOrderConverter;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class SecondaryConnectionStreamTest extends EventSourcingMessagingTestCase
{
    public function test_aggregate_stream_lives_on_the_connection_its_attribute_points_at(): void
    {
        $secondaryConnection = $this->connectionForTenantB()->createContext()->getDbalConnection();
        foreach (EventStreamSchemaFactory::for($secondaryConnection)->createTableSql(SecondaryOrder::STREAM) as $statement) {
            $secondaryConnection->executeStatement($statement);
        }

        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [SecondaryOrder::class, SecondaryOrderConverter::class],
            containerOrAvailableServices: [
                new SecondaryOrderConverter(),
                DbalConnectionFactory::class => $this->connectionForTenantA(),
                SecondaryOrder::CONNECTION_REFERENCE => $this->connectionForTenantB(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone->sendCommandWithRouting('secondaryOrder.place', new PlaceSecondaryOrder('order-1'));

        $primaryConnection = $this->connectionForTenantA()->createContext()->getDbalConnection();

        self::assertFalse(self::tableExists($primaryConnection, SecondaryOrder::STREAM));
        self::assertCount(1, $ecotone->getGateway(EventStore::class)->load(SecondaryOrder::STREAM));

        $ecotone->sendCommandWithRouting('secondaryOrder.cancel', new CancelSecondaryOrder('order-1'), metadata: ['aggregate.id' => 'order-1']);

        self::assertSame('cancelled', $ecotone->sendQueryWithRouting('secondaryOrder.getStatus', metadata: ['aggregate.id' => 'order-1']));
    }
}
