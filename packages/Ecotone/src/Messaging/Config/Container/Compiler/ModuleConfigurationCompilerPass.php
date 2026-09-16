<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Container\Compiler;

use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\Module;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;

/**
 * licence Apache-2.0
 */
final class ModuleConfigurationCompilerPass implements CompilerPass
{
    /**
     * @param Module[] $modules
     * @param object[] $extensionObjects
     */
    public function __construct(
        private array $modules,
        private $extensionObjects,
        private ServiceConfiguration $serviceConfiguration,
        private MessagingSystemConfiguration $configuration,
        private ModuleReferenceSearchService $moduleReferenceSearchService
    ) {

    }

    public function process(ContainerBuilder $builder): void
    {
        $extensionObjects = $this->extensionObjects;
        foreach ($this->modules as $module) {
            $extensionObjects = array_merge($extensionObjects, $module->getModuleExtensions($this->serviceConfiguration, $this->extensionObjects, $this->configuration->getInterfaceToCallRegistry()));
        }

        foreach ($this->modules as $module) {
            $module->prepare(
                $this->configuration,
                $extensionObjects,
                $this->moduleReferenceSearchService,
                $this->configuration->getInterfaceToCallRegistry(),
            );
        }
    }
}
