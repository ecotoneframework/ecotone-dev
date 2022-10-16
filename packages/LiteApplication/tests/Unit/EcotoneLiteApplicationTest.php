<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Unit;

use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Lite\Fixture\AddMoney;

/**
 * @internal
 */
class EcotoneLiteApplicationTest extends TestCase
{
    public function test_running_ecotone_lite_application()
    {
        $ecotoneLite = EcotoneLiteApplication::boostrap(
            configurationVariables: ['currentExchange' => 2],
            configuration: ServiceConfiguration::createWithDefaults()
                                ->withNamespaces(["Test\Ecotone\Lite\Fixture"])
                                ->withSkippedModulePackageNames(['amqp', 'dbal', 'jmsConverter', 'eventSourcing']),
            pathToRootCatalog: __DIR__ . '/../../'
        );

        $commandBus = $ecotoneLite->getCommandBus();
        $queryBus = $ecotoneLite->getQueryBus();

        $personId = 100;
        $commandBus->send(new AddMoney($personId, 1));

        $this->assertEquals(
            2,
            $queryBus->sendWithRouting('person.getMoney', $personId)
        );
    }
}
