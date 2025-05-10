<?php

namespace Ecotone\Modelling\Config\InstantRetry;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Precedence;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Attribute\InstantRetry;
use Ecotone\Modelling\CommandBus;
use Ramsey\Uuid\Uuid;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class InstantRetryModule implements AnnotationModule
{
    private array $commandBusesWithInstantRetry;

    private function __construct(array $commandBusesWithInstantRetry)
    {
        $this->commandBusesWithInstantRetry = $commandBusesWithInstantRetry;
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $commandBusesWithInstantRetry = [];
        $annotatedInterfaces = $annotationRegistrationService->findAnnotatedClasses(InstantRetry::class);

        foreach ($annotatedInterfaces as $annotatedInterface) {
            // Check if the interface extends CommandBus
            if (is_subclass_of($annotatedInterface, CommandBus::class)) {
                $instantRetryAttribute = $annotationRegistrationService->getAttributeForClass($annotatedInterface, InstantRetry::class);
                $commandBusesWithInstantRetry[$annotatedInterface] = $instantRetryAttribute;
            }
        }

        return new self($commandBusesWithInstantRetry);
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $configuration = ExtensionObjectResolver::resolveUnique(InstantRetryConfiguration::class, $extensionObjects, InstantRetryConfiguration::createWithDefaults());
        $messagingConfiguration->registerServiceDefinition(RetryStatusTracker::class, Definition::createFor(RetryStatusTracker::class, [false]));

        // Register interceptors for interfaces with InstantRetry attribute
        foreach ($this->commandBusesWithInstantRetry as $commandBusInterface => $instantRetryAttribute) {
            if (! $messagingConfiguration->isRunningForEnterpriseLicence()) {
                throw LicensingException::create('Instant retry attribute is available only for enterprise edition. Please contact support@ecotone.org for more information.');
            }

            $this->registerInterceptor(
                $messagingConfiguration,
                $interfaceToCallRegistry,
                $instantRetryAttribute->retryTimes,
                $instantRetryAttribute->exceptions,
                TypeDescriptor::create($commandBusInterface)->toString(),
                Precedence::CUSTOM_INSTANT_RETRY_PRECEDENCE,
            );
        }

        // Register global interceptors if enabled
        if ($configuration->isEnabledForCommandBus()) {
            $this->registerInterceptor($messagingConfiguration, $interfaceToCallRegistry, $configuration->getCommandBusRetryTimes(), $configuration->getCommandBuExceptions(), CommandBus::class, Precedence::GLOBAL_INSTANT_RETRY_PRECEDENCE);
        }
        if ($configuration->isEnabledForAsynchronousEndpoints()) {
            $this->registerInterceptor($messagingConfiguration, $interfaceToCallRegistry, $configuration->getAsynchronousRetryTimes(), $configuration->getAsynchronousExceptions(), AsynchronousRunningEndpoint::class, Precedence::GLOBAL_INSTANT_RETRY_PRECEDENCE);
        }
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof InstantRetryConfiguration;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }

    /**
     * @param string[] $exceptions
     */
    private function registerInterceptor(
        Configuration $messagingConfiguration,
        InterfaceToCallRegistry $interfaceToCallRegistry,
        int $retryAttempt,
        array $exceptions,
        string $pointcut,
        int $precedence,
    ): void
    {
        $instantRetryId = Uuid::uuid4()->toString();
        $messagingConfiguration->registerServiceDefinition($instantRetryId, Definition::createFor(InstantRetryInterceptor::class, [$retryAttempt, $exceptions, Reference::to(RetryStatusTracker::class)]));

        $messagingConfiguration
            ->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    $instantRetryId,
                    $interfaceToCallRegistry->getFor(InstantRetryInterceptor::class, 'retry'),
                    $precedence,
                    $pointcut
                )
            );
    }
}
