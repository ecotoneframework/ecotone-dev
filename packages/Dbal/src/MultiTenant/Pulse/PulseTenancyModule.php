<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant\Pulse;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseTenancyRegistration;

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
#[ModuleAnnotation]
final class PulseTenancyModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $pulseConfigurations = ExtensionObjectResolver::resolve(PulseConfiguration::class, $extensionObjects);
        $multiTenantConfigurations = ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $extensionObjects);
        if ($pulseConfigurations === [] || $multiTenantConfigurations === []) {
            return;
        }

        $headers = array_values(array_unique(array_map(static fn (MultiTenantConfiguration $configuration): string => $configuration->tenantHeaderName, $multiTenantConfigurations)));
        if (count($headers) > 1) {
            throw ConfigurationException::create('Pulse asks a multi-tenant service for one tenant at a time with a single tenant header, but the MultiTenantConfigurations of this service use the headers `' . implode('`, `', $headers) . '`, so it could not tell which header names the tenant. Give every MultiTenantConfiguration the same tenant header name, or remove the PulseConfiguration.');
        }

        $multiTenantReferences = array_map(static fn (MultiTenantConfiguration $configuration): string => $configuration->getReferenceName(), $multiTenantConfigurations);
        $serviceName = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults())->serviceName;
        $pulseConfiguration = $pulseConfigurations[0];
        $serviceMaps = ExtensionObjectResolver::resolve(DistributedServiceMap::class, $extensionObjects);
        $replyChannel = $pulseConfiguration->replyChannel ?? $pulseConfiguration->pulseServiceName;
        foreach ($serviceMaps as $serviceMap) {
            $replyChannel = $serviceMap->commandMapping[$pulseConfiguration->pulseServiceName] ?? $replyChannel;
        }

        foreach (ExtensionObjectResolver::resolve(\Ecotone\Api\ExtensionObject\MessageChannelBuilder::class, $extensionObjects) as $channelBuilder) {
            if (! $channelBuilder instanceof DbalBackedMessageChannelBuilder || ! in_array($channelBuilder->connectionReferenceName, $multiTenantReferences, true)) {
                continue;
            }

            $role = match ($channelBuilder->getMessageChannelName()) {
                $pulseConfiguration->requestChannel ?? $serviceName => 'request',
                $replyChannel => 'reply',
                default => null,
            };
            if ($role !== null) {
                throw ConfigurationException::create("Pulse {$role} channel '{$channelBuilder->getMessageChannelName()}' is a DBAL channel on connection reference `{$channelBuilder->connectionReferenceName}`, which is the multi-tenant connection, so a Pulse request sent without a tenant could not be read from it. Keep the Pulse channels on a dedicated non-tenant connection: DbalBackedMessageChannelBuilder::create('{$channelBuilder->getMessageChannelName()}', 'pulse_connection') with a DbalConnectionFactory registered under that reference, or use a broker transport (AMQP, Redis, SQS or Kafka) for it.");
            }
        }
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $multiTenantConfigurations = ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $serviceExtensions);
        if (ExtensionObjectResolver::resolve(PulseConfiguration::class, $serviceExtensions) === [] || $multiTenantConfigurations === []) {
            return [];
        }

        $tenants = [];
        foreach ($multiTenantConfigurations as $multiTenantConfiguration) {
            $tenants = [...$tenants, ...array_map('strval', array_keys($multiTenantConfiguration->tenantToConnectionMapping))];
        }

        $probeGroups = array_map(static fn (MultiTenantConfiguration $configuration): string => DbalBackedMessageChannelBuilder::depthProbeGroupOf($configuration->getReferenceName()), $multiTenantConfigurations);

        return [new PulseTenancyRegistration($multiTenantConfigurations[0]->tenantHeaderName, array_values(array_unique($tenants)), $probeGroups)];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
