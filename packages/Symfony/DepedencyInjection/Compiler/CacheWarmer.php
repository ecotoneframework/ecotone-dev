<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection\Compiler;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

class CacheWarmer implements CacheWarmerInterface
{
    public function __construct(private ContainerInterface $containerInterface)
    {
    }

    public function isOptional()
    {
        return false;
    }

    public function warmUp(string $cacheDir)
    {
        $this->containerInterface->get(ConfiguredMessagingSystem::class)->list();

        return [];
    }
}
