<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\InMemory;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\AddProduct;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\BasketWasCreated;
use Test\Ecotone\EventSourcing\Fixture\Basket\Event\ProductWasAddedToBasket;
use Test\Ecotone\EventSourcing\Fixture\BasketWithSynchronousEventDrivenSaga\SagaEventConverter;
use Test\Ecotone\EventSourcing\Fixture\BasketWithSynchronousEventDrivenSaga\SagaProjection;
use Test\Ecotone\EventSourcing\Fixture\BasketWithSynchronousEventDrivenSaga\SagaStarted;
use Test\Ecotone\EventSourcing\Fixture\BasketWithSynchronousEventDrivenSaga\SynchronousBasketList;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class SynchronousEventDrivenSagaTest extends TestCase
{
    public function test_product_is_added_by_synchronous_event_driven_saga(): void
    {
        $testSupport = EcotoneLite::bootstrapFlowTestingWithEventStore(
            containerOrAvailableServices: [new SagaProjection(), new SynchronousBasketList(), new SagaEventConverter(), new BasketEventConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Fixture\Basket',
                    'Test\Ecotone\EventSourcing\Fixture\BasketWithSynchronousEventDrivenSaga',
                ]),
            pathToRootCatalog: __DIR__ . '/../../'
        );

        $testSupport->sendCommand(new CreateBasket('1000'));

        self::assertEquals([
            new CreateBasket('1000'),
            new AddProduct('1000', 'chocolate'),
        ], $testSupport->getRecordedCommands());

        self::assertEquals([
            new BasketWasCreated('1000'),
            new SagaStarted('1000'),
            new ProductWasAddedToBasket('1000', 'chocolate'),
        ], $testSupport->getRecordedEvents());

        self::assertEquals(true, $testSupport->sendQueryWithRouting('isSagaStarted', '1000'));

        self::assertEquals([
            '1000' => ['chocolate'],
        ], $testSupport->sendQueryWithRouting('getALlBaskets'));
    }
}
