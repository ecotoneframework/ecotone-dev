<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\DelayedRetry;
use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\ErrorHandlerConfiguration;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Handler\Recoverability\DelayedRetryErrorHandler;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplate;
use Ecotone\Messaging\Handler\Router\HeaderRouter;
use Ecotone\Messaging\Handler\Router\RouterBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class ErrorHandlerModule extends NoExternalConfigurationModule implements AnnotationModule
{
    /**
     * @param ErrorHandlerConfiguration[] $perHandlerRetryConfigurations
     * @param list<string> $delayedRetryTargets
     */
    private function __construct(private array $perHandlerRetryConfigurations, private array $delayedRetryTargets)
    {
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $perHandlerRetryConfigurations = [];
        $delayedRetryTargets = [];

        foreach ($annotationRegistrationService->findAnnotatedMethods(DelayedRetry::class) as $delayedRetryMethod) {
            if (! $delayedRetryMethod->hasMethodAnnotation(\Ecotone\Messaging\Attribute\MessageConsumer::class)) {
                continue;
            }
            throw ConfigurationException::create(
                ErrorChannelExceptionMessages::delayedRetryOnInboundChannelAdapter($delayedRetryMethod->getClassName(), $delayedRetryMethod->getMethodName())
            );
        }

        $endpointMethods = $annotationRegistrationService->findAnnotatedMethods(\Ecotone\Messaging\Attribute\EndpointAnnotation::class);
        $asynchronousMethods = $annotationRegistrationService->findAnnotatedMethods(Asynchronous::class);

        foreach ($asynchronousMethods as $asynchronousMethod) {
            /** @var Asynchronous $asyncAnnotation */
            $asyncAnnotation = $asynchronousMethod->getAnnotationForMethod();

            $delayedRetry = null;
            $hasErrorChannel = false;
            foreach ($asyncAnnotation->asynchronousExecution as $endpointAnnotation) {
                if ($endpointAnnotation instanceof DelayedRetry) {
                    $delayedRetry = $endpointAnnotation;
                } elseif ($endpointAnnotation instanceof ErrorChannel) {
                    $hasErrorChannel = true;
                }
            }
            if ($delayedRetry === null) {
                continue;
            }

            foreach ($endpointMethods as $endpoint) {
                if ($endpoint->getClassName() !== $asynchronousMethod->getClassName()
                    || $endpoint->getMethodName() !== $asynchronousMethod->getMethodName()) {
                    continue;
                }

                /** @var \Ecotone\Messaging\Attribute\EndpointAnnotation $endpointAttribute */
                $endpointAttribute = $endpoint->getAnnotationForMethod();
                $handlerEndpointId = $endpointAttribute->getEndpointId();

                if ($hasErrorChannel) {
                    throw ConfigurationException::create(
                        ErrorChannelExceptionMessages::errorChannelAndDelayedRetryMutuallyExclusiveOnHandler($handlerEndpointId)
                    );
                }

                $generatedErrorChannelName = DelayedRetry::generateChannelName($handlerEndpointId);
                $retryTemplateBuilder = new RetryTemplateBuilder(
                    $delayedRetry->initialDelayInMilliseconds,
                    $delayedRetry->multiplier,
                    $delayedRetry->maxDelayInMilliseconds,
                    $delayedRetry->maxRetries,
                );

                $perHandlerRetryConfigurations[] = self::buildErrorHandlerConfig($generatedErrorChannelName, $retryTemplateBuilder, $delayedRetry->deadLetterChannel);
                $delayedRetryTargets[] = $handlerEndpointId . ': ' . self::scheduleOf($retryTemplateBuilder->build(), $delayedRetry->deadLetterChannel);
                break;
            }
        }

        $gatewayInterfacesWithDelayedRetry = $annotationRegistrationService->findAnnotatedClasses(DelayedRetry::class);
        foreach ($gatewayInterfacesWithDelayedRetry as $gatewayInterfaceFqn) {
            /** @var DelayedRetry $delayedRetry */
            $delayedRetry = AnnotatedDefinitionReference::getSingleAnnotationForClass($annotationRegistrationService, $gatewayInterfaceFqn, DelayedRetry::class);

            $errorChannelOnGateway = $annotationRegistrationService->findAnnotatedClasses(ErrorChannel::class);
            if (in_array($gatewayInterfaceFqn, $errorChannelOnGateway, true)) {
                throw ConfigurationException::create(
                    ErrorChannelExceptionMessages::errorChannelAndDelayedRetryMutuallyExclusiveOnGateway($gatewayInterfaceFqn)
                );
            }

            $generatedErrorChannelName = DelayedRetry::generateGatewayChannelName($gatewayInterfaceFqn);
            $retryTemplateBuilder = new RetryTemplateBuilder(
                $delayedRetry->initialDelayInMilliseconds,
                $delayedRetry->multiplier,
                $delayedRetry->maxDelayInMilliseconds,
                $delayedRetry->maxRetries,
            );
            $perHandlerRetryConfigurations[] = self::buildErrorHandlerConfig($generatedErrorChannelName, $retryTemplateBuilder, $delayedRetry->deadLetterChannel);
            $delayedRetryTargets[] = PulseFeature::shortNameOf($gatewayInterfaceFqn) . ': ' . self::scheduleOf($retryTemplateBuilder->build(), $delayedRetry->deadLetterChannel);
        }

        return new self($perHandlerRetryConfigurations, $delayedRetryTargets);
    }

    private static function scheduleOf(RetryTemplate $retryTemplate, ?string $deadLetterChannel): string
    {
        return $retryTemplate->describe() . ($deadLetterChannel === null ? ', then no further retry' : ', then dead letter channel ' . $deadLetterChannel);
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }
        $defaultErrorChannel = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $serviceExtensions, $serviceConfiguration)->defaultErrorChannel;
        $configurations = ExtensionObjectResolver::resolve(ErrorHandlerConfiguration::class, $serviceExtensions) ?: [ErrorHandlerConfiguration::createDefault()];
        $settings = ['Default error channel' => $defaultErrorChannel ?? 'not set'];
        $retriedChannels = [];
        foreach ($configurations as $configuration) {
            $settings['Retries on ' . $configuration->getErrorChannelName()] = self::scheduleOf($configuration->delayedRetryTemplate, $configuration->deadLetterChannel);
            $retriedChannels[$configuration->getErrorChannelName()] = $settings['Retries on ' . $configuration->getErrorChannelName()];
        }
        $perHandler = ' An endpoint with #[DelayedRetry] retries on its own schedule instead, and #[ErrorChannel] sends its failures to another channel. ErrorHandlerConfiguration::create(errorChannelName, RetryTemplateBuilder) sets the schedule of a channel.';

        return [new PulseFeature(
            package: ModulePackageList::CORE_PACKAGE,
            name: 'Error channel and delayed retries',
            enabled: $defaultErrorChannel !== null || $this->delayedRetryTargets !== [],
            settings: $settings,
            handlers: $this->delayedRetryTargets,
            hint: match (true) {
                $defaultErrorChannel !== null => sprintf("A message an asynchronous endpoint fails on, after its instant retries, is sent to error channel '%s'.", $defaultErrorChannel)
                    . (isset($retriedChannels[$defaultErrorChannel]) ? sprintf(' There it waits and is tried again: %s.', $retriedChannels[$defaultErrorChannel]) : ' No ErrorHandlerConfiguration retries that channel, so only its own subscribers decide what happens to the message.')
                    . $perHandler,
                $this->delayedRetryTargets !== [] => 'No default error channel is set, so a failing asynchronous message is not retried later, except on the endpoints listed here, which retry on their own schedule (#[DelayedRetry]).' . $perHandler,
                default => 'No default error channel is set, so a message an asynchronous endpoint fails on is not sent to an error channel and is not retried later: what happens to it is decided by the final failure strategy of its channel.' . $perHandler,
            },
            configuration: sprintf("ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('%s')", $defaultErrorChannel ?? $configurations[0]->getErrorChannelName()),
        )];
    }

    private static function buildErrorHandlerConfig(string $errorChannelName, RetryTemplateBuilder $retryTemplateBuilder, ?string $deadLetterChannel): ErrorHandlerConfiguration
    {
        return $deadLetterChannel !== null
            ? ErrorHandlerConfiguration::createWithDeadLetterChannel($errorChannelName, $retryTemplateBuilder, $deadLetterChannel)
            : ErrorHandlerConfiguration::create($errorChannelName, $retryTemplateBuilder);
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (! $this->hasErrorConfiguration($extensionObjects)) {
            $extensionObjects = [ErrorHandlerConfiguration::createDefault()];
        }
        $extensionObjects = array_merge($extensionObjects, $this->perHandlerRetryConfigurations);
        $instantRetryConfiguration = ExtensionObjectResolver::resolveUnique(InstantRetryConfiguration::class, $extensionObjects, InstantRetryConfiguration::createWithDefaults());
        $instantRetriesPerDelivery = $instantRetryConfiguration->isEnabledForAsynchronousEndpoints ? $instantRetryConfiguration->asynchronousRetryTimes : 0;

        $messagingConfiguration->registerServiceDefinition(RetryRunner::class, [new Reference(EcotoneClockInterface::class), new Reference(LoggingGateway::class)]);

        $hasAnyErrorHandlerConfiguration = false;

        /** @var ErrorHandlerConfiguration $extensionObject */
        foreach ($extensionObjects as $index => $extensionObject) {
            if (! ($extensionObject instanceof ErrorHandlerConfiguration)) {
                continue;
            }
            $hasAnyErrorHandlerConfiguration = true;

            $errorHandler = MessageProcessorActivatorBuilder::createForMethod(
                new Definition(DelayedRetryErrorHandler::class, [
                    $extensionObject->delayedRetryTemplate,
                    (bool)$extensionObject->deadLetterChannel,
                    $instantRetriesPerDelivery,
                    Reference::to(LoggingGateway::class),
                    Reference::to(MessageBuilder::class),
                ]),
                'handle',
            )
                ->withEndpointId('error_handler.' . $extensionObject->getErrorChannelName())
                ->withInputChannelName($extensionObject->getErrorChannelName())
                ->withMethodParameterConverters([
                    ReferenceBuilder::create('logger', LoggingGateway::class),
                ]);
            if ($extensionObject->deadLetterChannel) {
                $errorHandler = $errorHandler->withOutputMessageChannel($extensionObject->deadLetterChannel);
                $messagingConfiguration
                    ->registerDefaultChannelFor(SimpleMessageChannelBuilder::createPublishSubscribeChannel($extensionObject->deadLetterChannel));
            }

            $messagingConfiguration
                ->registerMessageHandler($errorHandler)
                ->registerDefaultChannelFor(SimpleMessageChannelBuilder::createPublishSubscribeChannel($extensionObject->getErrorChannelName()));
        }

        if ($hasAnyErrorHandlerConfiguration) {
            $messagingConfiguration->registerMessageHandler(
                RouterBuilder::create(
                    new Definition(HeaderRouter::class, [MessageHeaders::POLLED_CHANNEL_NAME]),
                    $interfaceToCallRegistry->getFor(HeaderRouter::class, 'route')
                )
                ->withEndpointId('error_handler.recoverability.reply.router')
                ->withInputChannelName('ecotone.recoverability.reply')
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }

    private function hasErrorConfiguration(array $extensionObjects): bool
    {
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof ErrorHandlerConfiguration) {
                return true;
            }
        }

        return false;
    }
}
