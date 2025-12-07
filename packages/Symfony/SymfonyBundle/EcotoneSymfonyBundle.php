<?php

namespace Ecotone\SymfonyBundle;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\AliasExternalReferenceForTesting;
use Ecotone\SymfonyBundle\DependencyInjection\EcotoneExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\RequiredReferencesCompilerPass;

/**
 * Class IntegrationMessagingBundle
 * @package Ecotone\SymfonyBundle
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class EcotoneSymfonyBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new AliasExternalReferenceForTesting());
        $container->addCompilerPass(new RequiredReferencesCompilerPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new EcotoneExtension();
    }
}
