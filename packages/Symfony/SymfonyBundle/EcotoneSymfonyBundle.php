<?php

namespace Ecotone\SymfonyBundle;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\SymfonyBundle\DepedencyInjection\Compiler\EcotoneCompilerPass;
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
    /**
     * @deprecated use ConfiguredMessagingSystem::class instead
     */
    public const CONFIGURED_MESSAGING_SYSTEM                 = ConfiguredMessagingSystem::class;
    public const APPLICATION_CONFIGURATION_CONTEXT   = 'messaging_system_application_context';

    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new EcotoneCompilerPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        return new EcotoneExtension();
    }
}
