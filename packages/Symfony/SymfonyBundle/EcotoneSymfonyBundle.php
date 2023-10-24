<?php

namespace Ecotone\SymfonyBundle;

use Ecotone\SymfonyBundle\DepedencyInjection\Compiler\AliasExternalReferenceForTesting;
use Ecotone\SymfonyBundle\DepedencyInjection\EcotoneExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Class IntegrationMessagingBundle
 * @package Ecotone\SymfonyBundle
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class EcotoneSymfonyBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
        $container->addCompilerPass(new AliasExternalReferenceForTesting());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new EcotoneExtension();
    }
}
