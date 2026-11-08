<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DocumentStore;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
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
final class DbalDocumentStorePulseFeatures
{
    /**
     * @param object[] $serviceExtensions
     * @return PulseFeature[]
     */
    public static function reportedFor(array $serviceExtensions, DbalConfiguration $dbalConfiguration): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }

        $storage = $dbalConfiguration->inMemoryDocumentStore ? 'in memory' : 'database, connection ' . $dbalConfiguration->documentStoreConnectionReference;
        return [new PulseFeature(
            package: ModulePackageList::DBAL_PACKAGE,
            name: 'Document store',
            enabled: $dbalConfiguration->enableDbalDocumentStore,
            settings: $dbalConfiguration->enableDbalDocumentStore ? [
                'Storage' => $storage,
                'Aggregate repository' => PulseFeature::onOff($dbalConfiguration->enableDocumentStoreStateStoredRepository),
            ] : [],
            handlers: [],
            hint: $dbalConfiguration->enableDbalDocumentStore
                ? sprintf('The document store keeps JSON documents in named collections (%s), read and written by id through the DocumentStore gateway.', $storage) . ($dbalConfiguration->enableDocumentStoreStateStoredRepository ? ' State-stored aggregates are saved in it too, one document per aggregate.' : ' State-stored aggregates are not saved in it; withDocumentStore(enableDocumentStoreStateStoredRepository: true) does that.')
                : 'No DocumentStore is registered on the database, so neither the application nor Ecotone can keep documents there.',
            configuration: 'DbalConfiguration::createWithDefaults()->withDocumentStore(isDocumentStoreEnabled: true)',
        )];
    }
}
