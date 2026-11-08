<?php

declare(strict_types=1);

namespace Ecotone\Dbal\ObjectManager;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
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
final class ObjectManagerPulseFeatures
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

        $flushedAfter = array_keys(array_filter([
            'a command bus call' => $dbalConfiguration->clearAndFlushObjectManagerOnCommandBus,
            'a message an asynchronous endpoint consumes' => $dbalConfiguration->clearObjectManagerOnAsynchronousEndpoints,
            'a projection batch' => $dbalConfiguration->clearAndFlushObjectManagerOnProjectionBatch,
        ]));

        return [
            new PulseFeature(
                package: ModulePackageList::DBAL_PACKAGE,
                name: 'Doctrine ORM flush and clear',
                enabled: $flushedAfter !== [],
                settings: [
                    'Command bus' => PulseFeature::onOff($dbalConfiguration->clearAndFlushObjectManagerOnCommandBus),
                    'Asynchronous endpoints' => PulseFeature::onOff($dbalConfiguration->clearObjectManagerOnAsynchronousEndpoints),
                    'Projection batches' => PulseFeature::onOff($dbalConfiguration->clearAndFlushObjectManagerOnProjectionBatch),
                ],
                handlers: [],
                hint: $flushedAfter === []
                    ? 'Ecotone does not flush or clear the Doctrine entity manager: handlers flush on their own, and a long-running consumer keeps the entities it loaded in memory from one message to the next.'
                    : sprintf('When the service uses Doctrine ORM, its entity manager is flushed after %s, then cleared: what a handler changed is written without an explicit flush, and the next message reads its entities fresh instead of from what a previous message left in memory.', implode(', after ', $flushedAfter)),
                configuration: 'DbalConfiguration::createWithDefaults()->withClearAndFlushObjectManagerOnCommandBus(true)->withClearAndFlushObjectManagerOnAsynchronousEndpoints(true)->withClearAndFlushObjectManagerOnProjectionBatch(true)',
            ),
            new PulseFeature(
                package: ModulePackageList::DBAL_PACKAGE,
                name: 'Doctrine ORM repositories',
                enabled: $dbalConfiguration->enableDoctrineORMRepositories,
                settings: $dbalConfiguration->enableDoctrineORMRepositories ? [
                    'Connection' => $dbalConfiguration->doctrineORMRepositoryConnectionReference ?? DbalConnectionReference::DEFAULT,
                    'Aggregates' => $dbalConfiguration->doctrineORMClasses === null ? 'every aggregate that is an ORM entity' : implode(', ', array_map(PulseFeature::shortNameOf(...), $dbalConfiguration->doctrineORMClasses)),
                ] : [],
                handlers: [],
                hint: $dbalConfiguration->enableDoctrineORMRepositories
                    ? 'An aggregate that is a Doctrine ORM entity is loaded and saved through the entity manager, so a command handler on the aggregate needs no repository of its own.'
                    : 'Aggregates are not loaded or saved through Doctrine ORM: an aggregate that is an ORM entity needs a repository of its own.',
                configuration: 'DbalConfiguration::createWithDefaults()->withDoctrineORMRepositories(isORMEnabled: true)',
            ),
        ];
    }
}
