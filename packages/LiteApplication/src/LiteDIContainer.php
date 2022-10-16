<?php

namespace Ecotone\Lite;

use DI\Container;
use DI\ContainerBuilder;
use Ecotone\Messaging\Config\ReferenceTypeFromNameResolver;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\InMemoryConfigurationVariableService;

class LiteDIContainer implements \Ecotone\Lite\GatewayAwareContainer, ReferenceTypeFromNameResolver
{
    private Container $container;

    public function __construct(ServiceConfiguration $serviceConfiguration, bool $cacheConfiguration, array $configurationVariables)
    {
        $builder = new ContainerBuilder();

        if ($cacheConfiguration) {
            $cacheDirectoryPath = $serviceConfiguration->getCacheDirectoryPath() ?? sys_get_temp_dir();
            $builder = $builder
                ->enableCompilation($cacheDirectoryPath . '/ecotone')
                ->writeProxiesToFile(true, __DIR__ . '/ecotone/proxies')
                ->ignorePhpDocErrors(true);
        }

        $this->container = $builder->build();
        $this->container->set(ConfigurationVariableService::REFERENCE_NAME, InMemoryConfigurationVariableService::create($configurationVariables));
    }

    public function get($id)
    {
        return $this->container->get($id);
    }

    public function has($id)
    {
        return $this->container->has($id);
    }

    public function set(string $id, object $service)
    {
        $this->container->set($id, $service);
    }

    public function addGateway(string $referenceName, object $gateway): void
    {
        $this->container->set($referenceName, $gateway);
    }

    public function resolve(string $referenceName): Type
    {
        return TypeDescriptor::create($referenceName);
    }
}
