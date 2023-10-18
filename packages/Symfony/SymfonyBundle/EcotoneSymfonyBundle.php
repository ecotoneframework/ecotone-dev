<?php

namespace Ecotone\SymfonyBundle;

use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
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
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new EcotoneExtension();
    }

    public function boot()
    {
        /** @var ProxyFactory $proxyFactory */
        $proxyFactory = $this->container->get(ProxyFactory::class);
        $proxyFactory->registerProxyAutoloader();
    }

    public function shutdown()
    {
        /** @var ProxyFactory $proxyFactory */
        $proxyFactory = $this->container->get(ProxyFactory::class);
        $proxyFactory->unregisterProxyAutoloader();
    }
}
