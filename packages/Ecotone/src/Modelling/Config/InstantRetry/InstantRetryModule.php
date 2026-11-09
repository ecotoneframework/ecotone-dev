<?php

namespace Ecotone\Modelling\Config\InstantRetry;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\InstantRetry;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Messaging\Attribute\IdentifiedAnnotation;
use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Modelling\Config\DatabaseTransaction\CommandsWithoutDatabaseTransaction;
use Ecotone\Modelling\Config\DatabaseTransaction\TransactionStatusTracker;
use Symfony\Component\Uid\Uuid;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class InstantRetryModule implements AnnotationModule
{
    /**
     * @param array<string, InstantRetry> $commandBusOverrides
     * @param list<array{string, InstantRetry}> $endpointOverrides
     * @param list<string> $handlersWithoutTransaction
     */
    private function __construct(private CommandsWithoutDatabaseTransaction $commandsWithoutDatabaseTransaction, private array $commandBusOverrides, private array $endpointOverrides, private array $handlersWithoutTransaction)
    {
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $commandBusOverrides = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(InstantRetry::class) as $commandBusInterface) {
            $commandBusOverrides[$commandBusInterface] = $annotationRegistrationService->getAttributeForClass($commandBusInterface, InstantRetry::class);
        }
        $endpointOverrides = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(InstantRetry::class) as $annotatedMethod) {
            if ($annotatedMethod->hasMethodAnnotation(MessageConsumer::class)) {
                $endpointOverrides[] = [$annotatedMethod->getMethodAnnotationsWithType(MessageConsumer::class)[0]->getEndpointId(), $annotatedMethod->getAnnotationForMethod()];
            }
        }

        $handlersWithoutTransaction = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(Asynchronous::class) as $annotatedMethod) {
            foreach ($annotatedMethod->getMethodAnnotationsWithType(Asynchronous::class) as $asynchronous) {
                if (array_filter($asynchronous->asynchronousExecution, static fn (object $attribute): bool => $attribute instanceof WithoutDatabaseTransaction) !== [] && $annotatedMethod->hasMethodAnnotation(IdentifiedAnnotation::class)) {
                    $handlersWithoutTransaction[] = $annotatedMethod->getMethodAnnotationsWithType(IdentifiedAnnotation::class)[0]->getEndpointId();
                }
            }
        }

        return new self(
            handlersWithoutTransaction: $handlersWithoutTransaction,
            commandsWithoutDatabaseTransaction: CommandsWithoutDatabaseTransaction::find($annotationRegistrationService, $interfaceToCallRegistry),
            commandBusOverrides: $commandBusOverrides,
            endpointOverrides: $endpointOverrides,
        );
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $configuration = ExtensionObjectResolver::resolveUnique(InstantRetryConfiguration::class, $extensionObjects, InstantRetryConfiguration::createWithDefaults());
        $defaults = InstantRetryDefaults::from($extensionObjects);
        $messagingConfiguration->registerServiceDefinition(RetryStatusTracker::class, Definition::createFor(RetryStatusTracker::class, [false]));
        $messagingConfiguration->registerServiceDefinition(TransactionStatusTracker::class, Definition::createFor(TransactionStatusTracker::class, [false]));
        $messagingConfiguration->registerServiceDefinition(CommandsWithoutDatabaseTransaction::class, $this->commandsWithoutDatabaseTransaction->getDefinition());

        if ($configuration->isEnabledForCommandBus === true) {
            $this->registerInterceptor($messagingConfiguration, $interfaceToCallRegistry, $configuration->commandBusRetryTimes, $configuration->commandBuExceptions, CommandBus::class, Precedence::GLOBAL_INSTANT_RETRY_PRECEDENCE);
        }
        if ($configuration->isEnabledForCommandBus === null && $defaults->forCommandBus()) {
            $this->registerRetryByDefault(
                $messagingConfiguration,
                $interfaceToCallRegistry,
                CommandBusInstantRetryByDefault::class,
                [$this->instantRetry($configuration->commandBusRetryTimes, $configuration->commandBuExceptions), Reference::to(CommandsWithoutDatabaseTransaction::class)],
                CommandBus::class,
            );
        }
        if ($configuration->isEnabledForAsynchronousEndpoints === true) {
            $this->registerInterceptor($messagingConfiguration, $interfaceToCallRegistry, $configuration->asynchronousRetryTimes, $configuration->asynchronousExceptions, AsynchronousRunningEndpoint::class, Precedence::GLOBAL_INSTANT_RETRY_PRECEDENCE);
        }
        if ($configuration->isEnabledForAsynchronousEndpoints === null && $defaults->apply) {
            $this->registerRetryByDefault(
                $messagingConfiguration,
                $interfaceToCallRegistry,
                AsynchronousEndpointInstantRetryByDefault::class,
                [$this->instantRetry($configuration->asynchronousRetryTimes, $configuration->asynchronousExceptions), $defaults->databaseTransactions->onAsynchronousEndpoints, $defaults->databaseTransactions->asynchronousEndpointsWithoutTransaction],
                AsynchronousRunningEndpoint::class,
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return InstantRetryPulseFeatures::reportedFor(serviceExtensions: $serviceExtensions, commandBusOverrides: $this->commandBusOverrides, endpointOverrides: $this->endpointOverrides, handlersWithoutTransaction: $this->handlersWithoutTransaction);
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
    ): void {
        $instantRetryId = Uuid::v7()->toRfc4122();
        $messagingConfiguration->registerServiceDefinition($instantRetryId, Definition::createFor(InstantRetryInterceptor::class, [$retryAttempt, $exceptions, Reference::to(RetryStatusTracker::class), Reference::to(TransactionStatusTracker::class)]));

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

    /**
     * @param string[] $exceptions
     */
    private function instantRetry(int $retryAttempt, array $exceptions): Definition
    {
        return Definition::createFor(InstantRetryInterceptor::class, [$retryAttempt, $exceptions, Reference::to(RetryStatusTracker::class), Reference::to(TransactionStatusTracker::class)]);
    }

    private function registerRetryByDefault(
        Configuration $messagingConfiguration,
        InterfaceToCallRegistry $interfaceToCallRegistry,
        string $interceptorClass,
        array $arguments,
        string $pointcut,
    ): void {
        $interceptorId = Uuid::v7()->toRfc4122();
        $messagingConfiguration->registerServiceDefinition($interceptorId, Definition::createFor($interceptorClass, $arguments));

        $messagingConfiguration->registerAroundMethodInterceptor(
            AroundInterceptorBuilder::create(
                $interceptorId,
                $interfaceToCallRegistry->getFor($interceptorClass, 'retry'),
                Precedence::GLOBAL_INSTANT_RETRY_PRECEDENCE,
                $pointcut
            )
        );
    }
}
