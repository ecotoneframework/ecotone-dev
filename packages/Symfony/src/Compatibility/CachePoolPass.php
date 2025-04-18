<?php

declare(strict_types=1);

namespace Symfony\Component\Cache\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A mock implementation of CachePoolPass for compatibility with Symfony 7.x
 * 
 * licence Apache-2.0
 */
class CachePoolPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // This is a mock implementation that does nothing
        // It's only purpose is to ensure compatibility with Symfony 7.x
    }
}
