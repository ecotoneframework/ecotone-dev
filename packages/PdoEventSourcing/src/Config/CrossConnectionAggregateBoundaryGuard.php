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
        if ($dbalConfiguration->isDoctrineORMRepositoriesEnabled() && in_array($aggregateClass, $dbalConfiguration->getDoctrineORMClasses() ?? [], true)) {
            return ['name' => 'Doctrine ORM repository', 'connection' => $dbalConfiguration->getDoctrineORMRepositoryConnectionReference()];
        }

        if (
            $dbalConfiguration->isEnableDocumentStoreStateStoredRepository()
            && ! $dbalConfiguration->isInMemoryDocumentStore()
            && ($dbalConfiguration->getDocumentStoreRelatedAggregates() === null || in_array($aggregateClass, $dbalConfiguration->getDocumentStoreRelatedAggregates(), true))
        ) {
            return ['name' => 'document store repository', 'connection' => $dbalConfiguration->getDocumentStoreConnectionReference()];
        }

        return null;
    }
}
