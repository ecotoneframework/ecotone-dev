<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
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
final class EventStorePulseFeatures
{
    /**
     * @param object[] $serviceExtensions
     * @return PulseFeature[]
     */
    public static function reportedFor(array $serviceExtensions, EventSourcingConfiguration $configuration): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }

        $storage = $configuration->isInMemory() ? 'in memory' : 'database, connection ' . $configuration->getConnectionReferenceName();

        return [new PulseFeature(
            package: ModulePackageList::EVENT_SOURCING_PACKAGE,
            name: 'Event store',
            enabled: true,
            settings: [
                'Storage' => $storage,
                'Default stream table' => $configuration->getDefaultEventStreamTableName(),
                'Load batch size' => (string) $configuration->getLoadBatchSize(),
            ],
            handlers: [],
            hint: sprintf('The events of event-sourced aggregates are appended to their event streams (%s, in table %s unless a stream names its own) and read back %d events at a time, when an aggregate is loaded and when a projection catches up.', $storage, $configuration->getDefaultEventStreamTableName(), $configuration->getLoadBatchSize()),
            configuration: sprintf("EventSourcingConfiguration::createWithDefaults()->withLoadBatchSize(%d)->withDefaultEventStreamTableName('%s')", $configuration->getLoadBatchSize(), $configuration->getDefaultEventStreamTableName()),
        )];
    }
}
