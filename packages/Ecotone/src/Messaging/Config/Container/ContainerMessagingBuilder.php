<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;

class ContainerMessagingBuilder
{
    private InterfaceToCallRegistry $interfaceToCallRegistry;

    /**
     * Map of endpointId => endpointRunnerReferenceName
     * @var array<string, string> $pollingEndpoints
     */
    private array $pollingEndpoints = [];
    private ServiceConfiguration $applicationConfiguration;

    public function __construct(private ContainerBuilder $builder, ?InterfaceToCallRegistry $interfaceToCallRegistry = null, ?ServiceConfiguration $serviceConfiguration = null)
    {
        $this->interfaceToCallRegistry = $interfaceToCallRegistry ?? InterfaceToCallRegistry::createEmpty();
        $this->applicationConfiguration = $serviceConfiguration ?? ServiceConfiguration::createWithDefaults();
    }

    public function getInterfaceToCall(InterfaceToCallReference $interfaceToCallReference): InterfaceToCall
    {
        return $this->interfaceToCallRegistry->getFor($interfaceToCallReference->getClassName(), $interfaceToCallReference->getMethodName());
    }

    public function getInterfaceToCallRegistry(): InterfaceToCallRegistry
    {
        return $this->interfaceToCallRegistry;
    }

    public function getServiceConfiguration(): ServiceConfiguration
    {
        return $this->applicationConfiguration;
    }

    public function registerPollingEndpoint(string $endpointId, string $endpointRunnerReferenceName): void
    {
        if (isset($this->pollingEndpoints[$endpointId])) {
            throw new \InvalidArgumentException("Endpoint with id {$endpointId} already exists");
        }
        $this->pollingEndpoints[$endpointId] = $endpointRunnerReferenceName;
    }

    public function getPollingEndpoints(): array
    {
        return $this->pollingEndpoints;
    }

    public function register(string|Reference $id, object|array|string $definition = []): Reference
    {
        return $this->builder->register($id, $definition);
    }

    public function replace(string|Reference $id, object|array|string $definition = []): Reference
    {
        return $this->builder->replace($id, $definition);
    }

    public function getDefinition(string|Reference $id): Definition
    {
        return $this->builder->getDefinition($id);
    }

    /**
     * @return array<string, Definition|Reference|DefinedObject>
     */
    public function getDefinitions(): array
    {
        return $this->builder->getDefinitions();
    }

    public function has(string|Reference $id): bool
    {
        return $this->builder->has($id);
    }
}
