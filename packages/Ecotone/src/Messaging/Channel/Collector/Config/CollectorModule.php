<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\Collector\Collector;
use Ecotone\Messaging\Channel\Collector\CollectorChannelInterceptor;
use Ecotone\Messaging\Channel\Collector\CollectorSenderInterceptor;
use Ecotone\Messaging\Channel\Collector\Config\CollectorChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\Collector\DefaultCollectorProxy;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\ReferenceBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\CommandBus;

#[ModuleAnnotation]
final class CollectorModule extends NoExternalConfigurationModule implements AnnotationModule
{
    const ECOTONE_COLLECTOR_DEFAULT_PROXY = 'ecotone.collector.default_proxy';

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        $collectorConfigurations = ExtensionObjectResolver::resolve(CollectorConfiguration::class, $extensionObjects);

        $takenChannelNames = [];
        foreach ($collectorConfigurations as $collectorConfiguration) {
            $collector = new Collector();
            foreach ($collectorConfiguration->getTogetherCollectedChannelNames() as $collectedChannelName) {
                if (in_array($collectedChannelName, $takenChannelNames)) {
                    throw ConfigurationException::create("Channel {$collectedChannelName} is already taken by another collector");
                }

                $takenChannelNames[] = $collectedChannelName;
                $messagingConfiguration->registerChannelInterceptor(
                    new CollectorChannelInterceptorBuilder($collectedChannelName, $collector),
                );
            }

            $messagingConfiguration->registerAroundMethodInterceptor(
                AroundInterceptorReference::createWithDirectObjectAndResolveConverters(
                    $interfaceToCallRegistry,
                    new CollectorSenderInterceptor($collector, $collectorConfiguration->getSendCollectedToMessageChannelName(), $serviceConfiguration->getDefaultErrorChannel()),
                    'send',
                    Precedence::COLLECTOR_SENDER_PRECEDENCE,
                    CommandBus::class . '||' . AsynchronousRunningEndpoint::class
                )
            );
        }

        if ($collectorConfigurations !== []) {
            $messagingConfiguration->registerMessageHandler(
                ServiceActivatorBuilder::createWithDirectReference(
                    new DefaultCollectorProxy(),
                    'proxy'
                )
                    ->withInputChannelName(self::ECOTONE_COLLECTOR_DEFAULT_PROXY)
                    ->withMethodParameterConverters([
                        ReferenceBuilder::create('configuredMessagingSystem', ConfiguredMessagingSystem::class)
                    ])
            );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof CollectorConfiguration
            ||
            $extensionObject instanceof ServiceConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }
}