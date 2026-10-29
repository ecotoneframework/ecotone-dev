<?php

namespace Ecotone\Modelling\Config\InstantRetry;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\InstantRetry;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Modelling\Config\DatabaseTransaction\TransactionStatusTracker;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;
use Symfony\Component\Uid\Uuid;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class InstantRetryModule implements AnnotationModule
{
    /**
     * @param list<string> $commandBusOverrides
     * @param list<string> $endpointOverrides
     */
    private function __construct(private array $commandBusOverrides, private array $endpointOverrides)
    {
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $commandBusOverrides = [];
        foreach ($annotationRegistrationService->findAnnotatedClasses(InstantRetry::class) as $commandBusInterface) {
            $commandBusOverrides[] = self::overrideOf(PulseFeature::shortNameOf($commandBusInterface), $annotationRegistrationService->getAttributeForClass($commandBusInterface, InstantRetry::class));
        }
        $endpointOverrides = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(InstantRetry::class) as $annotatedMethod) {
            $consumers = $annotatedMethod->getMethodAnnotationsWithType(MessageConsumer::class);
            if ($consumers !== []) {
                $endpointOverrides[] = self::overrideOf($consumers[0]->getEndpointId(), $annotatedMethod->getAnnotationForMethod());
            }
        }

        return new self($commandBusOverrides, $endpointOverrides);
    }

    private static function overrideOf(string $target, InstantRetry $instantRetry): string
    {
        return sprintf('%s: %d extra attempts on %s', $target, $instantRetry->retryTimes, PulseFeature::exceptionsOf($instantRetry->exceptions));
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $configuration = ExtensionObjectResolver::resolveUnique(InstantRetryConfiguration::class, $extensionObjects, InstantRetryConfiguration::createWithDefaults());
        $messagingConfiguration->registerServiceDefinition(RetryStatusTracker::class, Definition::createFor(RetryStatusTracker::class, [false]));
        $messagingConfiguration->registerServiceDefinition(TransactionStatusTracker::class, Definition::createFor(TransactionStatusTracker::class, [false]));

        if ($configuration->isEnabledForCommandBus) {
            $this->registerInterceptor($messagingConfiguration, $interfaceToCallRegistry, $configuration->commandBusRetryTimes, $configuration->commandBuExceptions, CommandBus::class, Precedence::GLOBAL_INSTANT_RETRY_PRECEDENCE);
        }
        if ($configuration->isEnabledForAsynchronousEndpoints) {
            $this->registerInterceptor($messagingConfiguration, $interfaceToCallRegistry, $configuration->asynchronousRetryTimes, $configuration->asynchronousExceptions, AsynchronousRunningEndpoint::class, Precedence::GLOBAL_INSTANT_RETRY_PRECEDENCE);
        }
    }

    /**
     * @inheritDoc
     */
    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }
        $configuration = ExtensionObjectResolver::resolveUnique(InstantRetryConfiguration::class, $serviceExtensions, InstantRetryConfiguration::createWithDefaults());

        return [
            new PulseFeature(
                package: ModulePackageList::CORE_PACKAGE,
                name: 'Instant retries on asynchronous endpoints',
                enabled: $configuration->isEnabledForAsynchronousEndpoints,
                settings: self::settingsOf($configuration->isEnabledForAsynchronousEndpoints, $configuration->asynchronousRetryTimes, $configuration->asynchronousExceptions),
                handlers: $this->endpointOverrides,
                hint: $configuration->isEnabledForAsynchronousEndpoints
                    ? sprintf('A message an asynchronous endpoint fails on is handled once and, when that fails, tried again right away up to %d more times (%d attempts in total) on %s, before it goes to the error channel. An endpoint with #[InstantRetry] uses its own count instead.', $configuration->asynchronousRetryTimes, $configuration->asynchronousRetryTimes + 1, PulseFeature::exceptionsOf($configuration->asynchronousExceptions))
                    : 'A message an asynchronous endpoint fails on goes straight to the error channel, with no immediate second attempt.',
                configuration: sprintf('InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(isEnabled: true, retryTimes: %d, retryExceptions: %s)', $configuration->asynchronousRetryTimes, PulseFeature::exceptionClassesOf($configuration->asynchronousExceptions)),
            ),
            new PulseFeature(
                package: ModulePackageList::CORE_PACKAGE,
                name: 'Instant retries on the command bus',
                enabled: $configuration->isEnabledForCommandBus,
                settings: self::settingsOf($configuration->isEnabledForCommandBus, $configuration->commandBusRetryTimes, $configuration->commandBuExceptions),
                handlers: $this->commandBusOverrides,
                hint: $configuration->isEnabledForCommandBus
                    ? sprintf('A command sent through the command bus is handled once and, when that fails, tried again right away up to %d more times (%d attempts in total) on %s, before the exception reaches the caller. A CommandBus interface with #[InstantRetry] uses its own count instead.', $configuration->commandBusRetryTimes, $configuration->commandBusRetryTimes + 1, PulseFeature::exceptionsOf($configuration->commandBuExceptions))
                    : 'A command sent through the command bus is sent once: when its handler fails, the exception reaches the caller with no immediate second attempt. A CommandBus interface with #[InstantRetry] still retries.',
                configuration: sprintf('InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(isEnabled: true, retryTimes: %d, retryExceptions: %s)', $configuration->commandBusRetryTimes, PulseFeature::exceptionClassesOf($configuration->commandBuExceptions)),
            ),
        ];
    }

    /**
     * @param string[] $exceptions
     * @return array<string, string>
     */
    private static function settingsOf(bool $isEnabled, int $retryTimes, array $exceptions): array
    {
        return $isEnabled ? ['Extra attempts' => (string) $retryTimes, 'Retried on' => PulseFeature::exceptionsOf($exceptions)] : [];
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
}
