<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\AddProduct;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\BasketListProjection\BasketList;
use Test\Ecotone\EventSourcing\Fixture\BasketListProjection\BasketListConfiguration;

final class PollingProjectionTest extends TestCase
{
    public function test_running_polling_projection(): void
    {
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [BasketListConfiguration::class],
            containerOrAvailableServices: [new BasketList(), new BasketEventConverter()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces(['Test\Ecotone\EventSourcing\Fixture\Basket'])
        );

        $ecotoneLite->sendCommand(new CreateBasket('1000'));
        $ecotoneLite->run(BasketList::PROJECTION_NAME);

        self::assertEquals(['1000' => []], $ecotoneLite->sendQueryWithRouting('getALlBaskets'));

        $ecotoneLite->sendCommand(new AddProduct('1000', 'milk'));
        $ecotoneLite->run(BasketList::PROJECTION_NAME);

        self::assertEquals(['1000' => ['milk']], $ecotoneLite->sendQueryWithRouting('getALlBaskets'));
    }
}
