<?php

namespace Ecotone\SymfonyBundle;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\SymfonyBundle\DepedencyInjection\Compiler\EcotoneCompilerPass;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MessagingSystemFactory
{
    public function __construct(
        private ContainerInterface $container,
        private ServiceCacheConfiguration $cacheConfiguration,
        private ReferenceSearchService $referenceSearchService,
    ) {
    }

    public function __invoke(): ConfiguredMessagingSystem
    {
        $useCache = $this->cacheConfiguration->shouldUseCache();
        $configuration = EcotoneCompilerPass::getMessagingConfiguration($this->container, $useCache);

        $messagingSystem = $configuration->buildMessagingSystemFromConfiguration($this->referenceSearchService);
        $this->referenceSearchService->setConfiguredMessagingSystem($messagingSystem);

        return $messagingSystem;
    }
}
