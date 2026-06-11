<?php

namespace Ecotone\SymfonyBundle\DependencyInjection\Compiler;

use Ecotone\Lite\InMemoryContainerImplementation;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * licence Apache-2.0
 */
class AliasExternalReferenceForTesting implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (! $container->hasParameter('ecotone.external_references')) {
            return;
        }

        foreach ($container->getParameter('ecotone.external_references') as $id) {
            $aliasId = InMemoryContainerImplementation::ALIAS_PREFIX . $id;
            if ($container->has($aliasId)) {
                continue;
            }
            if ($container->hasDefinition($id) || $container->hasAlias($id)) {
                $container->setAlias($aliasId, $id)
                    ->setPublic(true);
            }
        }
    }
}
