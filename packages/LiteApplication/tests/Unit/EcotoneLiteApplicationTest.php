<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Unit;

use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Lite\Fixture\AddMoney;
use Test\Ecotone\Lite\Fixture\Ticketing\InMemoryTicketRepository;
use Test\Ecotone\Lite\Fixture\Ticketing\TicketRepository;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class EcotoneLiteApplicationTest extends TestCase
{
    public function test_running_ecotone_lite_application_without_cache()
    {
        $ecotoneLite = EcotoneLiteApplication::boostrap(
            configurationVariables: ['currentExchange' => 2],
            serviceConfiguration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces(["Test\Ecotone\Lite\Fixture"])
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
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

    public function test_running_ecotone_lite_application_with_cache()
    {
        $cacheDirectoryPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . Uuid::v7()->toRfc4122();
        $this->getCachedConfiguration($cacheDirectoryPath);
        $ecotoneLite = $this->getCachedConfiguration($cacheDirectoryPath);

        $commandBus = $ecotoneLite->getCommandBus();
        $queryBus = $ecotoneLite->getQueryBus();

        $personId = 100;
        $commandBus->send(new AddMoney($personId, 1));

        $this->assertEquals(
            2,
            $queryBus->sendWithRouting('person.getMoney', $personId)
        );
    }

    public function test_autowired_services_receive_ecotone_gateway_over_instance_registered_under_interface_id()
    {
        $ecotoneLite = EcotoneLiteApplication::bootstrap(
            serviceConfiguration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces(["Test\Ecotone\Lite\Fixture\Ticketing"])
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
            pathToRootCatalog: __DIR__ . '/../../',
            classesToRegister: [TicketRepository::class => new InMemoryTicketRepository()],
        );

        $ecotoneLite->getCommandBus()->sendWithRouting('ticket.register', 'ticket-1');

        $this->assertSame(
            'ticket-1',
            $ecotoneLite->getQueryBus()->sendWithRouting('ticket.getRegistered', 'ticket-1')
        );
    }

    private function getCachedConfiguration(string $cacheDirectory): ConfiguredMessagingSystem
    {
        return EcotoneLiteApplication::boostrap(
            configurationVariables: ['currentExchange' => 2],
            serviceConfiguration: ServiceConfiguration::createWithDefaults()
                ->withNamespaces(["Test\Ecotone\Lite\Fixture"])
                ->withCacheDirectoryPath($cacheDirectory)
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
            cacheConfiguration: true,
            pathToRootCatalog: __DIR__ . '/../../'
        );
    }
}
