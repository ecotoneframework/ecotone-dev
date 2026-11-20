<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Api\ExtensionObject\CoroutineMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Messaging\Channel\PollableChannel\InMemory\InMemoryQueueAcknowledgeInterceptorBuilder;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
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
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $channels = ExtensionObjectResolver::resolve(CoroutineMessageChannelBuilder::class, $extensionObjects);
        if ($channels !== []) {
            $messagingConfiguration->addCompilerPass(new CoroutineChannelConsumerRegistration(array_map(static fn (CoroutineMessageChannelBuilder $channel) => $channel->messageChannelName, $channels)));
            $messagingConfiguration->registerServiceDefinition(CoroutineMessageFailureLogger::class, new Definition(CoroutineMessageFailureLogger::class, [array_map(static fn (CoroutineMessageChannelBuilder $channel) => $channel->messageChannelName, $channels), new Reference(LoggingGateway::class)]));
            $messagingConfiguration->registerAroundMethodInterceptor(AroundInterceptorBuilder::create(
                CoroutineMessageFailureLogger::class,
                $interfaceToCallRegistry->getFor(CoroutineMessageFailureLogger::class, 'log'),
                Precedence::EXCEPTION_LOGGING_PRECEDENCE,
                AsynchronousRunningEndpoint::class,
            ));
            $messagingConfiguration->registerServiceDefinition(CoroutineMessageRelease::class, new Definition(CoroutineMessageRelease::class));
            $messagingConfiguration->registerAroundMethodInterceptor(AroundInterceptorBuilder::create(
                CoroutineMessageRelease::class,
                $interfaceToCallRegistry->getFor(CoroutineMessageRelease::class, 'release'),
                Precedence::BETWEEN_INSTANT_RETRY_AND_TRANSACTION_PRECEDENCE,
                CommandBus::class . '||' . AsynchronousRunningEndpoint::class . '||' . MessageGateway::class,
            ));
        }
        foreach ($channels as $channel) {
            foreach (ExtensionObjectResolver::resolve(PollingMetadata::class, $extensionObjects) as $pollingMetadata) {
                if ($pollingMetadata->getEndpointId() === $channel->messageChannelName && $pollingMetadata->coroutines > 1) {
                    throw ConfigurationException::create("Coroutine channel `{$channel->messageChannelName}` is consumed in the process that sent the message. Remove PollingMetadata::withCoroutines() for `{$channel->messageChannelName}` and set its concurrency with CoroutineMessageChannelBuilder::withCoroutines() instead.");
                }
            }
            $messagingConfiguration->registerChannelInterceptor(new CoroutineMessageHoldingInterceptorBuilder($channel->messageChannelName));
            $messagingConfiguration->registerChannelInterceptor(new InMemoryQueueAcknowledgeInterceptorBuilder($channel->messageChannelName, FinalFailureStrategy::IGNORE, true));
            if (! $messagingConfiguration->isRunningForEnterpriseLicence()) {
                throw LicensingException::create("Coroutine channel `{$channel->messageChannelName}` requires an Ecotone Enterprise licence. Pass a licence key when bootstrapping Ecotone, or register another message channel builder under `{$channel->messageChannelName}`.");
            }
            if (! extension_loaded('swoole') && ! extension_loaded('openswoole')) {
                throw ConfigurationException::create("Coroutine channel `{$channel->messageChannelName}` requires the swoole or openswoole extension. Install either extension for the PHP running the application, or register another message channel builder under `{$channel->messageChannelName}`.");
            }
        }
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
