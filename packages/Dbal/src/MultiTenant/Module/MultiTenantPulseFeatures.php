<?php

declare(strict_types=1);

namespace Ecotone\Dbal\MultiTenant\Module;

use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;

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
final class MultiTenantPulseFeatures
{
    /**
     * @param object[] $serviceExtensions
     * @return PulseFeature[]
     */
    public static function reportedFor(array $serviceExtensions): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }
        $configurations = ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $serviceExtensions);
        $settings = [];
        foreach ($configurations as $configuration) {
            $settings['Tenants of ' . PulseFeature::shortNameOf($configuration->getReferenceName())] = implode(', ', array_keys($configuration->tenantToConnectionMapping)) . ' (header ' . $configuration->tenantHeaderName . ')';
        }

        return [new PulseFeature(
            package: ModulePackageList::DBAL_PACKAGE,
            name: 'Multi-tenant connections',
            enabled: $configurations !== [],
            settings: $settings,
            handlers: [],
            hint: $configurations === []
                ? 'Every message uses the same database connections, whichever tenant it belongs to.'
                : 'Each message runs on the database connection of its tenant, chosen by the tenant header it carries, so the tenants keep their data, outbox and dead letters apart. Pulse asks for each tenant separately.',
            configuration: "MultiTenantConfiguration::create(tenantHeaderName: 'tenant', tenantToConnectionMapping: ['tenant_a' => 'tenant_a_connection'])",
        )];
    }
}
