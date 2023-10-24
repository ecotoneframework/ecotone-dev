<?php

namespace Ecotone\SymfonyBundle\DepedencyInjection\Compiler;

use Ecotone\Lite\InMemoryContainerImplementation;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AliasExternalReferenceForTesting implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if (! $container->hasParameter('ecotone.external_references')) {
            return;
        }

        foreach ($container->getParameter('ecotone.external_references') as $id) {
            if ($container->hasDefinition($id)) {
                $container->setAlias(InMemoryContainerImplementation::ALIAS_PREFIX.$id, $id)
                    ->setPublic(true);
            }
        }
    }
}
