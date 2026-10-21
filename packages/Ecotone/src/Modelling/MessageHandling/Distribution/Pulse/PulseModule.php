<?php

declare(strict_types=1);

namespace Ecotone\Modelling\MessageHandling\Distribution\Pulse;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\MessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Messaging\Config\Annotation\AnnotatedDefinitionReference;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\BootstrapConfiguration;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\NullableMessageChannel;
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
 *
 * @internal
 */
final class PulseModule implements AnnotationModule
{
    /**
     * @param string[] $asynchronousHandlerChannels
     */
    private function __construct(private array $asynchronousHandlerChannels)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $asynchronousHandlerChannels = [];
        foreach ($annotationRegistrationService->findAnnotatedMethods(Asynchronous::class) as $registration) {
            $asynchronousHandlerChannels = [...$asynchronousHandlerChannels, ...$registration->getAnnotationForMethod()->getChannelName()];
        }
        foreach ($annotationRegistrationService->findAnnotatedClasses(Asynchronous::class) as $className) {
            $asynchronousHandlerChannels = [...$asynchronousHandlerChannels, ...AnnotatedDefinitionReference::getSingleAnnotationForClass($annotationRegistrationService, $className, Asynchronous::class)->getChannelName()];
        }

        return new self(array_values(array_unique($asynchronousHandlerChannels)));
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $pulseConfigurations = ExtensionObjectResolver::resolve(PulseConfiguration::class, $extensionObjects);
        if ($pulseConfigurations === []) {
            return;
        }

        if (count($pulseConfigurations) > 1) {
            throw ConfigurationException::create('PulseConfiguration was registered ' . count($pulseConfigurations) . ' times, but a service answers to exactly one Pulse service. Keep a single #[ServiceContext] returning PulseConfiguration::createWithDefaults(pulseServiceName: ...) and remove the others.');
        }

        $pulseServiceName = $pulseConfigurations[0]->pulseServiceName;
        if (! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create('Ecotone Pulse is available only as part of Ecotone Enterprise. Provide a licence key, or remove the PulseConfiguration from your #[ServiceContext]. See https://docs.ecotone.tech/enterprise for details.');
        }

        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        if ($serviceConfiguration->serviceName === ServiceConfiguration::DEFAULT_SERVICE_NAME) {
            throw ConfigurationException::create("PulseConfiguration needs this service to be named, so Pulse can tell which service answered. Set it with ServiceConfiguration::createWithDefaults()->withServiceName('my_service') in a #[ServiceContext].");
        }

        $serviceMaps = ExtensionObjectResolver::resolve(DistributedServiceMap::class, $extensionObjects);
        if (! self::anyMapReaches($serviceMaps, $pulseServiceName)) {
            throw ConfigurationException::create("PulseConfiguration answers Pulse service {$pulseServiceName}, but no DistributedServiceMap maps it to a channel, so the reply could not be routed. Add DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: '{$pulseServiceName}', channelName: 'channel_pulse_consumes') to a #[ServiceContext], or change the Pulse service name in PulseConfiguration::createWithDefaults().");
        }

        $bootstrapConfiguration = ExtensionObjectResolver::resolveUnique(BootstrapConfiguration::class, $extensionObjects, BootstrapConfiguration::createWithDefaults());
        $sections = self::sectionReferences(ExtensionObjectResolver::resolve(PulseSnapshotSectionRegistration::class, $extensionObjects));

        $messagingConfiguration->registerServiceDefinition(
            PulseSnapshot::class,
            Definition::createFor(PulseSnapshot::class, [
                $serviceConfiguration->serviceName,
                $pulseServiceName,
                PulseFrameworkResolver::resolve($bootstrapConfiguration),
                array_map(self::topologyOf(...), $serviceMaps),
                $this->channelsOf($extensionObjects),
                $sections,
                new Reference(ConfiguredMessagingSystem::class),
                new Reference(EcotoneClockInterface::class),
            ])
        );
        $messagingConfiguration->registerMessageHandler(
            MessageProcessorActivatorBuilder::createForMethod(PulseSnapshot::class, $interfaceToCallRegistry->getFor(PulseSnapshot::class, 'snapshotJson'))
                ->withInputChannelName(PulseSnapshot::ROUTING_KEY)
                ->withMethodParameterConverters([
                    HeaderBuilder::createOptional('sourceService', DistributedBusHeader::DISTRIBUTED_SOURCE_SERVICE_NAME),
                ])
        );
    }

    /**
     * @return list<array{name: string, kind: string}>
     */
    private function channelsOf(array $extensionObjects): array
    {
        $channels = [];
        foreach (ExtensionObjectResolver::resolve(MessageChannelBuilder::class, $extensionObjects) as $channelBuilder) {
            $name = $channelBuilder->getMessageChannelName();
            if ($channelBuilder->isPollable() && $name !== NullableMessageChannel::CHANNEL_NAME) {
                $channels[$name] = ['name' => $name, 'kind' => in_array($name, $this->asynchronousHandlerChannels, true) ? 'async' : 'queue'];
            }
        }
        ksort($channels);

        return array_values($channels);
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
     * @param PulseSnapshotSectionRegistration[] $registrations
     * @return array<string, Reference>
     */
    private static function sectionReferences(array $registrations): array
    {
        $references = [];
        foreach ($registrations as $registration) {
            if (in_array($registration->sectionName, PulseSnapshot::CORE_KEYS, true)) {
                throw ConfigurationException::create("Pulse snapshot section `{$registration->sectionName}` collides with a key the snapshot already has. Name the section anything but " . implode(', ', PulseSnapshot::CORE_KEYS) . '.');
            }
            if (array_key_exists($registration->sectionName, $references)) {
                throw ConfigurationException::create("Pulse snapshot section `{$registration->sectionName}` was registered twice, so the snapshot could hold only one of them. Register each section under its own name, or remove the duplicate registration.");
            }
            $references[$registration->sectionName] = new Reference($registration->serviceReference);
        }

        return $references;
    }

    /**
     * @param DistributedServiceMap[] $serviceMaps
     */
    private static function anyMapReaches(array $serviceMaps, string $serviceName): bool
    {
        foreach ($serviceMaps as $serviceMap) {
            if (array_key_exists($serviceName, $serviceMap->commandMapping)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{referenceName: string, commandMapping: array<string, string>, eventSubscriptions: array<mixed>, asynchronousChannel: string|null}
     */
    private static function topologyOf(DistributedServiceMap $serviceMap): array
    {
        $asynchronousChannel = null;
        foreach ($serviceMap->distributedBusAnnotations as $annotation) {
            if ($annotation instanceof AttributeDefinition && $annotation->getClassName() === Asynchronous::class) {
                $asynchronousChannel = $annotation->getArguments()[0];
            }
        }

        $subscriptions = [];
        foreach ($serviceMap->eventSubscriptions as $channel => $subscription) {
            $subscriptions[] = [
                'channel' => $channel,
                'subscriptionKeys' => $subscription['keys'],
                'excludePublishingServices' => $subscription['exclude'],
                'includePublishingServices' => $subscription['include'],
            ];
        }

        return [
            'referenceName' => $serviceMap->getReferenceName(),
            'commandMapping' => $serviceMap->commandMapping,
            'eventSubscriptions' => $subscriptions,
            'asynchronousChannel' => $asynchronousChannel,
        ];
    }
}
