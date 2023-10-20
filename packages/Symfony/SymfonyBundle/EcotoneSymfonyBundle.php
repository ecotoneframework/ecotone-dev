<?php

namespace Ecotone\SymfonyBundle;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\SymfonyBundle\DepedencyInjection\EcotoneExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Class IntegrationMessagingBundle
 * @package Ecotone\SymfonyBundle
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class EcotoneSymfonyBundle extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new EcotoneExtension();
    }

    public function boot()
    {
        /** @var ConfiguredMessagingSystem $proxyFactory */
        $proxyFactory = $this->container->get(ConfiguredMessagingSystem::class);
        $proxyFactory->boot();
    }

    public function shutdown()
    {
        /** @var ConfiguredMessagingSystem $proxyFactory */
        $proxyFactory = $this->container->get(ConfiguredMessagingSystem::class);
        $proxyFactory->terminate();
    }
}
