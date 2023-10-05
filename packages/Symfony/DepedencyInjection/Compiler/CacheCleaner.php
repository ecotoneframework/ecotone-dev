<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection\Compiler;

use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Symfony\Component\HttpKernel\CacheClearer\CacheClearerInterface;

class CacheCleaner implements CacheClearerInterface
{
    public function __construct(private ServiceCacheConfiguration $serviceCacheConfiguration)
    {
    }

    public function clear($cacheDir): void
    {
        MessagingSystemConfiguration::cleanCache($this->serviceCacheConfiguration);
    }
}
