<?php

namespace Ecotone\SymfonyBundle\DependencyInjection\Compiler;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\SymfonyContainer\EcotoneSymfonyContainerFactory;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * licence Apache-2.0
 */
class CacheWarmer implements CacheWarmerInterface
{
    public function __construct(
        private ConfiguredMessagingSystem $configuredMessagingSystem,
        private ProxyFactory $proxyFactory,
        private ServiceCacheConfiguration $serviceCacheConfiguration,
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $files = [];
        foreach ($this->configuredMessagingSystem->getGatewayList() as $gatewayReference) {
            $files[] = $this->proxyFactory->generateCachedProxyFileFor($gatewayReference, true);
        }

        return array_merge($files, EcotoneSymfonyContainerFactory::dumpedContainerFiles($this->serviceCacheConfiguration));
    }
}
