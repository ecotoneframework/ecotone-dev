<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\Collector\CollectorSenderInterceptor;
use Ecotone\Messaging\Channel\Collector\CollectorStorage;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\PollableChannel\GlobalPollableChannelConfiguration;
use Ecotone\Messaging\Channel\PollableChannel\PollableChannelConfiguration;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\CommandBus;

#[ModuleAnnotation]
final class CollectorModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public const ECOTONE_COLLECTOR_DEFAULT_PROXY = 'ecotone.collector.default_proxy';

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $globalPollableChannelConfiguration = ExtensionObjectResolver::resolveUnique(GlobalPollableChannelConfiguration::class, $extensionObjects, GlobalPollableChannelConfiguration::createWithDefaults());
        $pollableMessageChannels = ExtensionObjectResolver::resolve(MessageChannelBuilder::class, $extensionObjects);
        $pollableChannelConfigurations = ExtensionObjectResolver::resolve(PollableChannelConfiguration::class, $extensionObjects);

        $takenChannelNames = [];
        foreach ($pollableChannelConfigurations as $pollableChannelConfiguration) {
            if (in_array($pollableChannelConfiguration->getChannelName(), $takenChannelNames)) {
                throw ConfigurationException::create("Channel {$pollableChannelConfiguration->getChannelName()} is already taken by another collector");
            }

            $takenChannelNames[] = $pollableChannelConfiguration->getChannelName();
        }


        foreach ($pollableMessageChannels as $pollableMessageChannel) {
            $channelConfiguration = $globalPollableChannelConfiguration;

            foreach ($pollableChannelConfigurations as $pollableChannelConfiguration) {
                if ($pollableChannelConfiguration->getChannelName() === $pollableMessageChannel->getMessageChannelName()) {
                    $channelConfiguration = $pollableChannelConfiguration;
                }
            }

            if (! $channelConfiguration->isCollectorEnabled()) {
                continue;
            }

            $collector = new CollectorStorage();
            $messagingConfiguration->registerChannelInterceptor(
                new CollectorChannelInterceptorBuilder($pollableMessageChannel->getMessageChannelName(), $collector),
            );

            $messagingConfiguration->registerAroundMethodInterceptor(
                AroundInterceptorReference::createWithDirectObjectAndResolveConverters(
                    $interfaceToCallRegistry,
                    new CollectorSenderInterceptor($collector, $pollableMessageChannel->getMessageChannelName()),
                    'send',
                    Precedence::COLLECTOR_SENDER_PRECEDENCE,
                    CommandBus::class . '||' . AsynchronousRunningEndpoint::class
                )
            );
        }
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof PollableChannelConfiguration
            || $extensionObject instanceof GlobalPollableChannelConfiguration
            || ($extensionObject instanceof MessageChannelBuilder && $extensionObject->isPollable());
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}
