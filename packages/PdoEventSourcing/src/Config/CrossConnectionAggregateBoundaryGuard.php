<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Messaging\Config\ConfigurationException;

use function in_array;
use function sprintf;

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
final class CrossConnectionAggregateBoundaryGuard
{
    /**
     * @param class-string[] $stateStoredAggregateClasses
     */
    public static function assertEveryStateStoredAggregateSavesOnTheEventStoreConnection(
        array $stateStoredAggregateClasses,
        EventSourcingConfiguration $eventSourcingConfiguration,
        DbalConfiguration $dbalConfiguration,
    ): void {
        if ($eventSourcingConfiguration->isInMemory()) {
            return;
        }

        $eventStoreConnection = $eventSourcingConfiguration->getConnectionReferenceName();
        foreach ($stateStoredAggregateClasses as $aggregateClass) {
            $repository = self::dbalRepositoryOf($aggregateClass, $dbalConfiguration);
            if ($repository === null || $repository['connection'] === $eventStoreConnection) {
                continue;
            }

            throw ConfigurationException::create(sprintf(
                "State-stored aggregate %s is saved by the %s on connection '%s', but Dynamic Consistency Boundary keeps the aggregate's counter on the event store's connection '%s' -- "
                . 'the counter and the aggregate would commit in two transactions, so a failure between them would leave the boundary broken. '
                . 'Save the aggregate on the event store connection, or move the event store onto the repository connection.',
                $aggregateClass,
                $repository['name'],
                $repository['connection'],
                $eventStoreConnection,
            ));
        }
    }

    /**
     * @return array{name: string, connection: string}|null
     */
    private static function dbalRepositoryOf(string $aggregateClass, DbalConfiguration $dbalConfiguration): ?array
    {
        if ($dbalConfiguration->enableDoctrineORMRepositories && in_array($aggregateClass, $dbalConfiguration->doctrineORMClasses ?? [], true)) {
            return ['name' => 'Doctrine ORM repository', 'connection' => $dbalConfiguration->doctrineORMRepositoryConnectionReference];
        }

        if (
            $dbalConfiguration->enableDocumentStoreStateStoredRepository
            && ! $dbalConfiguration->inMemoryDocumentStore
            && ($dbalConfiguration->documentStoreRelatedAggregates === null || in_array($aggregateClass, $dbalConfiguration->documentStoreRelatedAggregates, true))
        ) {
            return ['name' => 'document store repository', 'connection' => $dbalConfiguration->documentStoreConnectionReference];
        }

        return null;
    }
}
