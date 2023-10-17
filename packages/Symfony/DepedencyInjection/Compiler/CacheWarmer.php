<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection\Compiler;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

class CacheWarmer implements CacheWarmerInterface
{
    public function __construct(
        private ContainerInterface $containerInterface,
        private ProxyFactory $proxyFactory
    ) {
    }

    public function isOptional()
    {
        return false;
    }

    public function warmUp(string $cacheDir)
    {
        /** @var ConfiguredMessagingSystem $messagingSystem */
        $messagingSystem = $this->containerInterface->get(ConfiguredMessagingSystem::class);

        foreach ($messagingSystem->getGatewayList() as $gatewayReference) {
            $this->proxyFactory->createWithCurrentConfiguration(
                $gatewayReference->getReferenceName(),
                $this->containerInterface,
                $gatewayReference->getInterfaceName()
            );
        }

        return [];
    }
}
