<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Gathering;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Dbal\Database\DbalTableManagerReference;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\BootstrapConfiguration;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConsoleInvocationResolver;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupStoreRegistration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\Support\MessageBuilder;

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
 */
final class DbalGatheringModule extends NoExternalConfigurationModule
{
    public function __construct(private bool $hasGatheringSplitters)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        foreach ($annotationRegistrationService->findAnnotatedMethods(Splitter::class) as $registration) {
            if ($registration->getAnnotationForMethod()->gatherChannelName !== '') {
                return new self(true);
            }
        }

        return new self(false);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (! $this->hasGatheringSplitters) {
            return;
        }
        $bootstrap = ExtensionObjectResolver::resolveUnique(BootstrapConfiguration::class, $extensionObjects, BootstrapConfiguration::createWithDefaults());
        $messagingConfiguration->registerServiceDefinition(GatheringTableManager::class, new Definition(GatheringTableManager::class, [
            ConsoleInvocationResolver::resolveConsolePrefix($bootstrap),
            DbalConnectionReference::DEFAULT,
        ]));
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());
        $messagingConfiguration->registerServiceDefinition(StoredGatheringMessages::class, new Definition(StoredGatheringMessages::class, [
            new Definition(OutboundMessageConverter::class, [DefaultHeaderMapper::createAllHeadersMapping()->getDefinition(), new Reference(MessageBuilder::class), MediaType::parseMediaType($serviceConfiguration->defaultSerializationMediaType)]),
            new Reference(ConversionService::class),
            new Reference(MessageBuilder::class),
        ]));
        $messagingConfiguration->registerServiceDefinition(DbalGatheredGroupStore::class, new Definition(DbalGatheredGroupStore::class, [
            CachedConnectionFactory::registerIn($messagingConfiguration, DbalConnectionReference::DEFAULT, DbalReconnectableConnectionFactory::definitionFor(DbalConnectionReference::DEFAULT)),
            new Reference(GatheringTableManager::class),
            new Reference(StoredGatheringMessages::class),
            new Reference(EcotoneClockInterface::class),
        ]));
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return $this->hasGatheringSplitters ? [new DbalTableManagerReference(GatheringTableManager::class), new GatheredGroupStoreRegistration(DbalGatheredGroupStore::class)] : [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
