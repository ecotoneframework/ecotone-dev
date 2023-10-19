<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection\Compiler;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

class CacheWarmer implements CacheWarmerInterface
{
    public function __construct(
        private ConfiguredMessagingSystem $configuredMessagingSystem,
        private ProxyFactory $proxyFactory
    ) {
    }

    public function isOptional()
    {
        return true;
    }

    public function warmUp(string $cacheDir)
    {
        $this->proxyFactory->warmUp($this->configuredMessagingSystem->getGatewayList());
        return [];
    }
}
