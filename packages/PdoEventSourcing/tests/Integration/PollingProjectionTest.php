<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\AddProduct;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\BasketListProjection\BasketList;
use Test\Ecotone\EventSourcing\Fixture\BasketListProjection\BasketListConfiguration;
use Test\Ecotone\EventSourcing\Fixture\ProductsProjection\Products;
use Test\Ecotone\EventSourcing\Fixture\ProductsProjection\ProductsConfiguration;

/**
 * @internal
 */
final class PollingProjectionTest extends TestCase
{
    public function test_running_polling_projection(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [BasketListConfiguration::class, BasketList::class, ProductsConfiguration::class, Products::class],
            containerOrAvailableServices: [new BasketList(), new Products(), new BasketEventConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\Basket']),
            pathToRootCatalog: __DIR__ . '/../../'
        );

        $ecotoneLite->sendCommand(new CreateBasket('1000'));
        $ecotoneLite->run(BasketList::PROJECTION_NAME, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 1000));

        self::assertEquals(['1000' => []], $ecotoneLite->sendQueryWithRouting('getALlBaskets'));
        self::assertEquals([], $ecotoneLite->sendQueryWithRouting('getALlProducts'));

        $ecotoneLite->sendCommand(new AddProduct('1000', 'milk'));

        $ecotoneLite->run(BasketList::PROJECTION_NAME, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 1000));
        $ecotoneLite->run(Products::PROJECTION_NAME, ExecutionPollingMetadata::createWithTestingSetup(maxExecutionTimeInMilliseconds: 1000));

        self::assertEquals(['1000' => ['milk']], $ecotoneLite->sendQueryWithRouting('getALlBaskets'));
        self::assertEquals(['milk' => 1], $ecotoneLite->sendQueryWithRouting('getALlProducts'));
    }
}
