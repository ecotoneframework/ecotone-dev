<?php

namespace Ecotone\SymfonyBundle;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\SymfonyBundle\DepedencyInjection\Compiler\EcotoneCompilerPass;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MessagingSystemFactory
{
    public function __construct(private ContainerInterface $container, private ReferenceSearchService $referenceSearchService)
    {
    }

    public function __invoke(): ConfiguredMessagingSystem
    {
        $configuration = EcotoneCompilerPass::getMessagingConfiguration($this->container, true);

        $messagingSystem = $configuration->buildMessagingSystemFromConfiguration($this->referenceSearchService);
        $this->referenceSearchService->setConfiguredMessagingSystem($messagingSystem);

        return $messagingSystem;
    }
}
