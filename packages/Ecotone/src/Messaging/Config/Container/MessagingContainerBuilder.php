<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\EndpointRunner;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundMethodInvocationProvider;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\InterceptorWithPointCut;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
class MessagingContainerBuilder
{
    private InterfaceToCallRegistry $interfaceToCallRegistry;

    /**
     * Map of endpointId => endpointRunnerReferenceName
     * @var array<string, string> $pollingEndpoints
     */
    private array $pollingEndpoints = [];
    private ServiceConfiguration $applicationConfiguration;

    /**
     * @param array<MethodInterceptorBuilder> $beforeInterceptors
     * @param array<AroundInterceptorBuilder> $aroundInterceptors
     * @param array<MethodInterceptorBuilder> $afterInterceptors
     */
    public function __construct(
        private ContainerBuilder $builder,
        ?InterfaceToCallRegistry $interfaceToCallRegistry = null,
        ?ServiceConfiguration $serviceConfiguration = null,
        private array $pollingMetadata = [],
        private array $beforeInterceptors = [],
        private array $aroundInterceptors = [],
        private array $afterInterceptors = [],
    ) {
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

    public function registerPollingEndpoint(string $endpointId, Definition $definition, bool $withContinuousPolling = false): void
    {
        if (isset($this->pollingEndpoints[$endpointId])) {
            throw InvalidArgumentException::create("Endpoint with id {$endpointId} already exists");
        }
        $runnerReference = new EndpointRunnerReference($endpointId);
        $className = $definition->getClassName();
        if (! is_a($className, EndpointRunner::class, true)) {
            throw InvalidArgumentException::create("Endpoint runner {$className} must implement " . EndpointRunner::class);
        }

        $pollingMetadata = $this->getPollingConfigurationForPolledEndpoint($endpointId, $withContinuousPolling);
        $this->registerPollingMetadata($pollingMetadata);
        $this->register($runnerReference, $definition);
        $this->pollingEndpoints[$endpointId] = $endpointId;
    }

    public function getPollingEndpoints(): array
    {
        return $this->pollingEndpoints;
    }

    public function register(string|Reference $id, DefinedObject|Definition|Reference $definition): Reference
    {
        return $this->builder->register($id, $definition);
    }

    public function replace(string|Reference $id, DefinedObject|Definition|Reference $definition): Reference
    {
        return $this->builder->replace($id, $definition);
    }

    public function getDefinition(string|Reference $id): Definition
    {
        return $this->builder->getDefinition($id);
    }

    private function registerPollingMetadata(PollingMetadata $pollingMetadata): Reference
    {
        $endpointId = $pollingMetadata->getEndpointId();
        $reference = new PollingMetadataReference($endpointId);
        $this->builder->replace($reference, $pollingMetadata);
        return $reference;
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

    private function getPollingConfigurationForPolledEndpoint(string $endpointId, bool $withContinuousPolling): PollingMetadata
    {
        if (array_key_exists($endpointId, $this->pollingMetadata)) {
            $pollingMetadata = $this->pollingMetadata[$endpointId];
        } else {
            $pollingMetadata = PollingMetadata::create($endpointId);
        }

        if ($this->applicationConfiguration->getDefaultErrorChannel() && $pollingMetadata->isErrorChannelEnabled() && ! $pollingMetadata->getErrorChannelName()) {
            $pollingMetadata = $pollingMetadata
                ->setErrorChannelName($this->applicationConfiguration->getDefaultErrorChannel());
        }
        if ($this->applicationConfiguration->getDefaultMemoryLimitInMegabytes() && ! $pollingMetadata->getMemoryLimitInMegabytes()) {
            $pollingMetadata = $pollingMetadata
                ->setMemoryLimitInMegaBytes($this->applicationConfiguration->getDefaultMemoryLimitInMegabytes());
        }
        if ($this->applicationConfiguration->getConnectionRetryTemplate() && ! $pollingMetadata->getConnectionRetryTemplate()) {
            $pollingMetadata = $pollingMetadata
                ->setConnectionRetryTemplate($this->applicationConfiguration->getConnectionRetryTemplate());
        }

        if ($withContinuousPolling) {
            $pollingMetadata = $pollingMetadata->setFixedRateInMilliseconds(1);
        }

        return $pollingMetadata;
    }

    public function interceptMethodCall(InterfaceToCallReference $interceptedInterfaceReference, array $endpointAnnotations, Definition $methodCallProviderDefinition): Definition
    {
        $interceptorsConfiguration = $this->getRelatedInterceptors(
            $interceptedInterfaceReference,
            $endpointAnnotations,
        );
        $aroundInterceptors = [];
        foreach ($interceptorsConfiguration->getAroundInterceptors() as $aroundInterceptor) {
            $aroundInterceptors[] = $aroundInterceptor->compileForInterceptedInterface($this, $interceptedInterfaceReference, $endpointAnnotations);
        }
        return new Definition(AroundMethodInvocationProvider::class, [
            $methodCallProviderDefinition,
            $aroundInterceptors,
        ]);
    }

    public function getRelatedInterceptors(
        InterfaceToCallReference $getInterceptedInterface,
        array $endpointAnnotations,
        array $requiredInterceptorNames = [],
    ): MethodInterceptorsConfiguration {
        $interfaceToCall = $this->interfaceToCallRegistry->getForReference($getInterceptedInterface);
        return new MethodInterceptorsConfiguration(
            $this->getRelatedInterceptorsFor($this->beforeInterceptors, $interfaceToCall, $endpointAnnotations, $requiredInterceptorNames),
            $this->getRelatedInterceptorsFor($this->aroundInterceptors, $interfaceToCall, $endpointAnnotations, $requiredInterceptorNames),
            $this->getRelatedInterceptorsFor($this->afterInterceptors, $interfaceToCall, $endpointAnnotations, $requiredInterceptorNames),
        );
    }

    /**
     * @template T of InterceptorWithPointCut
     * @param array<T> $interceptors
     * @param InterfaceToCall $interceptedInterface
     * @param array<AttributeDefinition> $endpointAnnotations
     * @param array<string> $requiredInterceptorNames
     * @return array<T>
     * @throws \Ecotone\Messaging\MessagingException
     */
    private static function getRelatedInterceptorsFor(array $interceptors, InterfaceToCall $interceptedInterface, array $endpointAnnotations, array $requiredInterceptorNames): iterable
    {
        Assert::allInstanceOfType($endpointAnnotations, AttributeDefinition::class);

        $relatedInterceptors = [];

        $endpointAnnotationsInstances = array_map(
            fn (AttributeDefinition $attributeDefinition) => $attributeDefinition->instance(),
            $endpointAnnotations
        );
        foreach ($interceptors as $interceptor) {
            foreach ($requiredInterceptorNames as $requiredInterceptorName) {
                if ($interceptor->hasName($requiredInterceptorName)) {
                    $relatedInterceptors[] = $interceptor;
                    break;
                }
            }

            if ($interceptor->doesItCutWith($interceptedInterface, $endpointAnnotationsInstances)) {
                $relatedInterceptors[] = $interceptor;
            }
        }

        return $relatedInterceptors;
    }
}
