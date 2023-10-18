<?php

namespace Test\Ecotone\Lite;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\InMemoryConfigurationVariableService;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\AnnotationFinder\Fixture\Usage\Attribute\MessageEndpoint\Gateway\FileSystem\GatewayWithReplyChannelExample;
use Test\Ecotone\Messaging\Fixture\Behat\Presend\CoinGateway;
use Test\Ecotone\Messaging\Fixture\Behat\Presend\MultiplyCoins;
use Test\Ecotone\Messaging\Fixture\Behat\Presend\Shop;

/**
 * Class EcotoneLiteConfigurationTest
 * @package Test\Ecotone\Lite
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 *
 * @internal
 */
class MessagingConfigurationTest extends TestCase
{
    public function test_registering_with_gateway_aware_container()
    {
        $container = InMemoryPSRContainer::createFromObjects([
            new MultiplyCoins(), new Shop(),
        ]);
        $serviceConfiguration = ServiceConfiguration::createWithDefaults()
                                ->withNamespaces(["Test\Ecotone\Messaging\Fixture\Behat\Presend"])
                                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]));

        $ecotone = EcotoneLite::bootstrap(
            containerOrAvailableServices: $container,
            configuration: $serviceConfiguration,
            allowGatewaysToBeRegisteredInContainer: true,
            pathToRootCatalog: __DIR__
        );

        $this->assertInstanceOf(
            CoinGateway::class,
            $container->get(CoinGateway::class)
        );

        $ecotone->terminate();
    }
}
