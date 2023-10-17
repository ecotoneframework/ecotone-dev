<?php

namespace Ecotone\Lite;

use DI\Container;
use DI\ContainerBuilder;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\InMemoryConfigurationVariableService;
use Psr\Container\ContainerInterface;

class LiteDIContainer implements ContainerInterface
{
    private Container $container;

    public function __construct(ServiceConfiguration $serviceConfiguration, bool $useCache, array $configurationVariables, array $classInstancesToRegister = [])
    {
        $builder = new ContainerBuilder();
        $serviceCacheConfiguration = new ServiceCacheConfiguration(
            $serviceConfiguration->getCacheDirectoryPath(),
            $useCache
        );

        if ($useCache) {
            $builder = $builder
                ->enableCompilation($serviceCacheConfiguration->getPath())
                /** @TODO verify if using __DIR__ is correct */
                ->writeProxiesToFile(true, __DIR__ . '/ecotone/proxies');
        }

        $this->container = $builder->build();
        $this->container->set(ConfigurationVariableService::REFERENCE_NAME, InMemoryConfigurationVariableService::create($configurationVariables));
        $this->container->set(ServiceCacheConfiguration::class, $serviceCacheConfiguration);
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
