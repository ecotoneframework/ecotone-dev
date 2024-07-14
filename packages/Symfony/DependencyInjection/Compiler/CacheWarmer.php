<?php

namespace Ecotone\SymfonyBundle\DependencyInjection\Compiler;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * licence Apache-2.0
 */
class CacheWarmer implements CacheWarmerInterface
{
    public function __construct(
        private ConfiguredMessagingSystem $configuredMessagingSystem,
        private ProxyFactory $proxyFactory
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, string $buildDir = null): array
    {
        foreach ($this->configuredMessagingSystem->getGatewayList() as $gatewayReference) {
            $this->proxyFactory->createWithCurrentConfiguration(
                $gatewayReference->getReferenceName(),
                $this->configuredMessagingSystem,
                $gatewayReference->getInterfaceName()
            );
        }

        return [];
    }
}
