<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\EventSourcing\EventSourcingConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\ProjectionFromCategoryUsingAggregatePerStream\FromCategoryUsingAggregatePerStreamProjection;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * @internal
 */
final class CategoryStreamTest extends EventSourcingMessagingTestCase
{
    public function test_handling_category_stream_when_stream_per_aggregate_persistence_is_enabled(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new FromCategoryUsingAggregatePerStreamProjection(), new BasketEventConverter(), new TicketEventConverter(), DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\ProjectionFromCategoryUsingAggregatePerStream',
                    'Test\Ecotone\EventSourcing\Fixture\Basket',
                    'Test\Ecotone\EventSourcing\Fixture\Ticket',
                ])
                ->withExtensionObjects([
                    EventSourcingConfiguration::createWithDefaults()
                        ->withStreamPerAggregatePersistenceStrategy(),
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        $ecotone
            ->sendCommand(new CreateBasket('1000'))
            ->sendCommand(new CreateBasket('1001'))
        ;

        self::assertEquals(2, $ecotone->sendQueryWithRouting('action_collector.getCount'));
    }
}
