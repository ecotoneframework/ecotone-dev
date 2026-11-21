<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\Priority;
use Ecotone\Api\Attribute\TimeToLive;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Messaging\Channel\PollableChannel\InMemory\InMemoryQueueAcknowledgeInterceptorBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\AsynchronousModule;
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
    private function __construct(private readonly AnnotationFinder $annotationFinder)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self($annotationRegistrationService);
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
        $handlers = $this->coroutineHandlers($channelNames, $extensionObjects);
        $this->refuseUnsupportedDeliveryAttributes($handlers, $interfaceToCallRegistry);
        $this->registerFailureLogging(configuration: $messagingConfiguration, registry: $interfaceToCallRegistry, channelNames: $channelNames, handlers: $handlers);
        $this->registerConsumers($messagingConfiguration, $channelNames);
        $this->waitOnThePinnedTestClock($messagingConfiguration, $extensionObjects);
    }

    private function waitOnThePinnedTestClock(Configuration $configuration, array $extensions): void
    {
        if (ExtensionObjectResolver::resolveUnique(TestConfiguration::class, $extensions, TestConfiguration::createWithDefaults())->timeAt !== null) {
            $configuration->registerServiceDefinition(CoroutineMessageChannelSleep::SERVICE_ID, new Reference(EcotoneClockInterface::class));
        }
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
                    throw ConfigurationException::create("Coroutine message channel `{$channel->messageChannelName}` has no separate polling consumer to configure: it is consumed in the process that sent the message. Remove PollingMetadata::withCoroutines() for `{$channel->messageChannelName}` and set concurrency with CoroutineMessageChannelBuilder::withCoroutines() instead.");
                }
            }
            if (! $configuration->isRunningForEnterpriseLicence()) {
                throw LicensingException::create("Coroutine message channel `{$channel->messageChannelName}` requires an Ecotone Enterprise licence. Supply the licenceKey: argument of EcotoneLite::bootstrap() or bootstrapFlowTesting(), or licenceKey in your framework's Ecotone configuration; alternatively, register another message channel builder under `{$channel->messageChannelName}`.");
            }
            if (! extension_loaded('swoole') && ! extension_loaded('openswoole')) {
                throw ConfigurationException::create("Coroutine message channel `{$channel->messageChannelName}` requires the swoole or openswoole extension. Install either extension for the PHP running the application. For tests on PHP without it, list every environment that runs on Swoole or OpenSwoole on the #[ServiceContext] method returning this builder and leave out only the test environment, for example #[Environment(['prod', 'dev'])] (Laravel names them 'production' and 'local'). In the test environment register a queue channel under the same name and drive it from the test: bootstrapFlowTesting(environment: 'test', extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('{$channel->messageChannelName}')]), then the flow test support's run('{$channel->messageChannelName}').");
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

    private function coroutineHandlers(array $channelNames, array $extensions): array
    {
        $endpointChannels = [];
        foreach (ExtensionObjectResolver::resolve(AsynchronousModule::class, $extensions) as $module) {
            $endpointChannels = array_merge($endpointChannels, $module->resolveChannels($extensions));
        }
        $handlers = array_merge(
            $this->annotationFinder->findAnnotatedMethods(CommandHandler::class),
            $this->annotationFinder->findAnnotatedMethods(EventHandler::class),
            $this->annotationFinder->findAnnotatedMethods(InternalHandler::class),
        );
        $coroutineHandlers = [];
        foreach ($handlers as $handler) {
            $channels = array_intersect($endpointChannels[$handler->getAnnotationForMethod()->getEndpointId()] ?? [], $channelNames);
            if ($channels !== []) {
                $coroutineHandlers[] = [$handler, $channels];
            }
        }
        return $coroutineHandlers;
    }

    private function refuseUnsupportedDeliveryAttributes(array $handlers, InterfaceToCallRegistry $registry): void
    {
        foreach ($handlers as [$handler, $channels]) {
            $interface = $registry->getFor($handler->getClassName(), $handler->getMethodName());
            foreach ([Priority::class => ['Priority', 'messages are handled in FIFO order.'], TimeToLive::class => ['TimeToLive', 'messages in this queue do not expire.']] as $attributeClass => [$attribute, $reason]) {
                if ($interface->hasAnnotation($attributeClass)) {
                    $channel = reset($channels);
                    $class = $handler->getClassName();
                    $method = $handler->getMethodName();
                    $endpointId = $handler->getAnnotationForMethod()->getEndpointId();
                    throw ConfigurationException::create("Coroutine message channel `{$channel}` does not support #[{$attribute}] on handler `{$class}::{$method}` (endpoint `{$endpointId}`): {$reason} Remove #[{$attribute}] from this handler, or use a channel that honours it.");
                }
            }
        }
    }

    private function registerRelease(Configuration $configuration, InterfaceToCallRegistry $registry): void
    {
        $configuration->registerServiceDefinition(CoroutineMessageChannelRelease::class, new Definition(CoroutineMessageChannelRelease::class));
        $configuration->registerAroundMethodInterceptor(AroundInterceptorBuilder::create(
            referenceName: CoroutineMessageChannelRelease::class,
            interfaceToCall: $registry->getFor(CoroutineMessageChannelRelease::class, 'release'),
            precedence: Precedence::BETWEEN_INSTANT_RETRY_AND_TRANSACTION_PRECEDENCE,
            pointcut: CommandBus::class . '||' . AsynchronousRunningEndpoint::class . '||' . MessageGateway::class . '||' . ConsoleCommand::class,
        ));
    }

    private function registerFailureLogging(Configuration $configuration, InterfaceToCallRegistry $registry, array $channelNames, array $handlers): void
    {
        $configuration->registerServiceDefinition(CoroutineMessageChannelFailureLogger::class, new Definition(CoroutineMessageChannelFailureLogger::class, [$channelNames, new Reference(LoggingGateway::class)]));
        if ($handlers !== []) {
            $pointcuts = array_map(static fn (array $entry) => $entry[0]->getClassName() . '::' . $entry[0]->getMethodName(), $handlers);
            $configuration->registerAroundMethodInterceptor(AroundInterceptorBuilder::create(
                referenceName: CoroutineMessageChannelFailureLogger::class,
                interfaceToCall: $registry->getFor(CoroutineMessageChannelFailureLogger::class, 'recordFailedEndpoint'),
                precedence: Precedence::DEFAULT_PRECEDENCE,
                pointcut: implode('||', array_unique($pointcuts)),
            ));
        }
        $configuration->registerAroundMethodInterceptor(AroundInterceptorBuilder::create(
            referenceName: CoroutineMessageChannelFailureLogger::class,
            interfaceToCall: $registry->getFor(CoroutineMessageChannelFailureLogger::class, 'log'),
            precedence: Precedence::EXCEPTION_LOGGING_PRECEDENCE,
            pointcut: AsynchronousRunningEndpoint::class,
        ));
    }

    private function registerConsumers(Configuration $configuration, array $channelNames): void
    {
        $configuration->addCompilerPass(new CoroutineMessageChannelConsumerRegistration($channelNames));
        $configuration->registerChannelInterceptor(new CoroutineMessageChannelConsumerStopInterceptorBuilder($channelNames));
        foreach ($channelNames as $channelName) {
            $configuration->registerChannelInterceptor(new CoroutineMessageChannelHoldingInterceptorBuilder($channelName));
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
