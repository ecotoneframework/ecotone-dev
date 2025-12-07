<?php

namespace Ecotone\SymfonyBundle\DependencyInjection\Compiler;

use Ecotone\Messaging\Config\ConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * licence Apache-2.0
 */
class RequiredReferencesCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $requiredReferences = $container->getParameter('ecotone.messaging_system_configuration.required_references');

        foreach ($requiredReferences as $referenceId => $errorMessage) {
            if (! $container->has($referenceId)) {
                throw ConfigurationException::create($errorMessage);
            }
        }
    }
}
