<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\Priority;
use Ecotone\Api\Attribute\TimeToLive;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\AsynchronousModule;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Messaging\Channel\PollableChannel\InMemory\InMemoryQueueAcknowledgeInterceptorBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Support\LicensingException;

#[ModuleAnnotation]
/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class CoroutineMessageChannelModule extends NoExternalConfigurationModule implements AnnotationModule
{
    private function __construct(private readonly array $handlers)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self(array_merge(
            $annotationRegistrationService->findAnnotatedMethods(CommandHandler::class),
            $annotationRegistrationService->findAnnotatedMethods(EventHandler::class),
            $annotationRegistrationService->findAnnotatedMethods(InternalHandler::class),
        ));
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $channels = ExtensionObjectResolver::resolve(CoroutineMessageChannelBuilder::class, $extensionObjects);
        if ($channels === []) {
            return;
        }
        $this->validateChannels(configuration: $messagingConfiguration, extensions: $extensionObjects, channels: $channels);
        $this->registerRelease($messagingConfiguration, $interfaceToCallRegistry);
        $channelNames = array_map(static fn (CoroutineMessageChannelBuilder $channel) => $channel->messageChannelName, $channels);
        $this->refuseUnsupportedDeliveryAttributes(channelNames: $channelNames, extensions: $extensionObjects, registry: $interfaceToCallRegistry);
        $this->registerFailureLogging(configuration: $messagingConfiguration, registry: $interfaceToCallRegistry, channelNames: $channelNames);
        $this->registerConsumers($messagingConfiguration, $channelNames);
    }

    private function validateChannels(Configuration $configuration, array $extensions, array $channels): void
    {
        $pollingConfigurations = ExtensionObjectResolver::resolve(PollingMetadata::class, $extensions);
        foreach ($channels as $channel) {
            foreach ($pollingConfigurations as $pollingMetadata) {
                if ($pollingMetadata->getEndpointId() !== $channel->messageChannelName) {
                    continue;
                }
                $this->refuseConsumerLimits($pollingMetadata);
                if ($pollingMetadata->coroutines > 1) {
                    throw ConfigurationException::create("Coroutine channel `{$channel->messageChannelName}` is consumed in the process that sent the message. Remove PollingMetadata::withCoroutines() for `{$channel->messageChannelName}` and set its concurrency with CoroutineMessageChannelBuilder::withCoroutines() instead.");
                }
            }
            if (! $configuration->isRunningForEnterpriseLicence()) {
                throw LicensingException::create("Coroutine message channel `{$channel->messageChannelName}` requires an Ecotone Enterprise licence. Supply the licenceKey: argument of EcotoneLite::bootstrap() or bootstrapFlowTesting(), or licenceKey in your framework's Ecotone configuration; alternatively, register another message channel builder under `{$channel->messageChannelName}`.");
            }
            if (! extension_loaded('swoole') && ! extension_loaded('openswoole')) {
                throw ConfigurationException::create("Coroutine message channel `{$channel->messageChannelName}` requires the swoole or openswoole extension. Install either extension for the PHP running the application. In tests without it, replace this registration with SimpleMessageChannelBuilder::createQueueChannel('{$channel->messageChannelName}') and consume it with FlowTestSupport::run('{$channel->messageChannelName}'), or register another message channel builder under `{$channel->messageChannelName}`.");
            }
        }
    }

    private function refuseConsumerLimits(PollingMetadata $metadata): void
    {
        $settings = [
            'withStopOnError' => $metadata->stopOnError,
            'withHandledMessageLimit' => $metadata->handledMessageLimit !== 0,
            'withExecutionTimeLimitInMilliseconds' => $metadata->executionTimeLimitInMilliseconds !== 0,
            'withExecutionAmountLimit' => $metadata->executionAmountLimit !== 0,
            'withMemoryLimitInMegaBytes' => ! in_array(needle: $metadata->memoryLimitInMegabytes, haystack: [0, PollingMetadata::DEFAULT_MEMORY_LIMIT_MEGABYTES], strict: true),
        ];
        foreach ($settings as $setting => $configured) {
            if (! $configured) {
                continue;
            }
            $reason = $setting === 'withMemoryLimitInMegaBytes'
                ? "it is consumed inside the sending process, which is not Ecotone's to stop when memory runs high."
                : 'it is consumed inside the sending process, so there is no separate consumer process to stop.';
            throw ConfigurationException::create("Coroutine message channel `{$metadata->getEndpointId()}` cannot use PollingMetadata::{$setting}(): {$reason} Remove this setting, or use a channel consumed with ecotone:run.");
        }
    }

    private function refuseUnsupportedDeliveryAttributes(array $channelNames, array $extensions, InterfaceToCallRegistry $registry): void
    {
        $endpointChannels = [];
        foreach (ExtensionObjectResolver::resolve(AsynchronousModule::class, $extensions) as $module) {
            $endpointChannels = array_merge($endpointChannels, $module->resolveChannels($extensions));
        }
        foreach ($this->handlers as $handler) {
            $channels = array_intersect($endpointChannels[$handler->getAnnotationForMethod()->getEndpointId()] ?? [], $channelNames);
            if ($channels === []) {
                continue;
            }
            $interface = $registry->getFor($handler->getClassName(), $handler->getMethodName());
            foreach ([Priority::class => ['Priority', 'messages are handled in FIFO order.'], TimeToLive::class => ['TimeToLive', 'messages in this queue do not expire.']] as $attributeClass => [$attribute, $reason]) {
                if ($interface->hasAnnotation($attributeClass)) {
                    $channel = reset($channels);
                    throw ConfigurationException::create("Coroutine message channel `{$channel}` does not support #[{$attribute}]: {$reason} Remove #[{$attribute}] from this handler, or use a channel that honours it.");
                }
            }
        }
    }

    private function registerRelease(Configuration $configuration, InterfaceToCallRegistry $registry): void
    {
        $configuration->registerServiceDefinition(CoroutineMessageRelease::class, new Definition(CoroutineMessageRelease::class));
        $configuration->registerAroundMethodInterceptor(AroundInterceptorBuilder::create(
            referenceName: CoroutineMessageRelease::class,
            interfaceToCall: $registry->getFor(CoroutineMessageRelease::class, 'release'),
            precedence: Precedence::BETWEEN_INSTANT_RETRY_AND_TRANSACTION_PRECEDENCE,
            pointcut: CommandBus::class . '||' . AsynchronousRunningEndpoint::class . '||' . MessageGateway::class . '||' . ConsoleCommand::class,
        ));
    }

    private function registerFailureLogging(Configuration $configuration, InterfaceToCallRegistry $registry, array $channelNames): void
    {
        $configuration->registerServiceDefinition(CoroutineMessageFailureLogger::class, new Definition(CoroutineMessageFailureLogger::class, [$channelNames, new Reference(LoggingGateway::class)]));
        $configuration->registerAroundMethodInterceptor(AroundInterceptorBuilder::create(
            referenceName: CoroutineMessageFailureLogger::class,
            interfaceToCall: $registry->getFor(CoroutineMessageFailureLogger::class, 'recordFailedEndpoint'),
            precedence: Precedence::DEFAULT_PRECEDENCE,
            pointcut: CommandHandler::class . '||' . EventHandler::class . '||' . InternalHandler::class,
        ));
        $configuration->registerAroundMethodInterceptor(AroundInterceptorBuilder::create(
            referenceName: CoroutineMessageFailureLogger::class,
            interfaceToCall: $registry->getFor(CoroutineMessageFailureLogger::class, 'log'),
            precedence: Precedence::EXCEPTION_LOGGING_PRECEDENCE,
            pointcut: AsynchronousRunningEndpoint::class,
        ));
    }

    private function registerConsumers(Configuration $configuration, array $channelNames): void
    {
        $configuration->addCompilerPass(new CoroutineChannelConsumerRegistration($channelNames));
        foreach ($channelNames as $channelName) {
            $configuration->registerChannelInterceptor(new CoroutineMessageHoldingInterceptorBuilder($channelName));
            $configuration->registerChannelInterceptor(new InMemoryQueueAcknowledgeInterceptorBuilder(
                relatedChannel: $channelName,
                finalFailureStrategy: FinalFailureStrategy::IGNORE,
                isAutoAcked: true,
            ));
        }
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
