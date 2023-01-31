<?php

namespace Ecotone\Lite;

use DI\Container;
use DI\ContainerBuilder;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\InMemoryConfigurationVariableService;
use Psr\Container\ContainerInterface;

class LiteDIContainer implements ContainerInterface
{
    private Container $container;

    public function __construct(ServiceConfiguration $serviceConfiguration, bool $cacheConfiguration, array $configurationVariables, array $classInstancesToRegister = [])
    {
        $builder = new ContainerBuilder();

        if ($cacheConfiguration) {
            $cacheDirectoryPath = $serviceConfiguration->getCacheDirectoryPath() ?? sys_get_temp_dir();
            $builder = $builder
                ->enableCompilation($cacheDirectoryPath . '/ecotone')
                ->writeProxiesToFile(true, __DIR__ . '/ecotone/proxies');
        }

        $this->container = $builder->build();
        $this->container->set(ConfigurationVariableService::REFERENCE_NAME, InMemoryConfigurationVariableService::create($configurationVariables));
        foreach ($classInstancesToRegister as $referenceName => $classInstance) {
            $this->container->set($referenceName, $classInstance);
        }
    }

    public function get($id)
    {
        return $this->container->get($id);
    }

    public function has($id): bool
    {
        return $this->container->has($id);
    }

    public function set(string $id, object $service)
    {
        $this->container->set($id, $service);
    }

    public function resolve(string $referenceName): Type
    {
        return TypeDescriptor::create($referenceName);
    }
}
