<?php

namespace Ecotone\Laravel;

use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Modelling\StateStoredRepository;
use Ecotone\Messaging\Handler\Enricher\PropertyPath;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionRegistry;
use Ecotone\Modelling\AggregateIdString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * licence Apache-2.0
 */
class EloquentRepository implements StateStoredRepository
{
    public function __construct(
        private AggregateDefinitionRegistry $aggregateDefinitionRegistry,
        private PropertyReaderAccessor $propertyReaderAccessor,
    ) {
    }

    public function canHandle(string $aggregateClassName): bool
    {
        return is_subclass_of($aggregateClassName, Model::class);
    }

    public function findBy(string $aggregateClassName, array $identifiers): ?object
    {
        $aggregate = call_user_func([$aggregateClassName, 'find'], array_pop($identifiers));
        $versionColumn = $this->versionColumnOf($aggregateClassName);

        if ($aggregate !== null && $versionColumn !== null) {
            $this->writeVersionProperty($aggregate, $versionColumn, (int) $aggregate->getAttribute($versionColumn));
        }

        return $aggregate;
    }

    public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
    {
        $versionColumn = $this->versionColumnOf($aggregate::class);
        if ($versionColumn === null) {
            $aggregate->save();

            return;
        }

        if ($aggregate->exists && $versionBeforeHandling === null) {
            $versionBeforeHandling = (int) $aggregate->getOriginal($versionColumn);
            $this->writeVersionProperty($aggregate, $versionColumn, $versionBeforeHandling + 1);
        }
        $versionAfterHandling = $this->propertyReaderAccessor->getPropertyValue(PropertyPath::createWith($versionColumn), $aggregate);
        $aggregate->setAttribute($versionColumn, $versionAfterHandling);

        if ($aggregate->exists) {
            $this->claimVersion($identifiers, $aggregate, $versionColumn, $versionBeforeHandling, $versionAfterHandling);
            $aggregate->save();

            return;
        }

        try {
            $aggregate->save();
        } catch (UniqueConstraintViolationException) {
            throw $this->staleAggregate($identifiers, $aggregate, $versionColumn, (int) $versionBeforeHandling);
        }
    }

    private function claimVersion(array $identifiers, Model $aggregate, string $versionColumn, int $versionBeforeHandling, int $versionAfterHandling): void
    {
        $claimed = $aggregate->newQueryWithoutScopes()
            ->whereKey($aggregate->getKey())
            ->where($versionColumn, $versionBeforeHandling)
            ->update([$versionColumn => $versionAfterHandling]);

        if ($claimed === 0) {
            throw $this->staleAggregate($identifiers, $aggregate, $versionColumn, $versionBeforeHandling);
        }
    }

    private function staleAggregate(array $identifiers, Model $aggregate, string $versionColumn, int $versionBeforeHandling): ConcurrencyException
    {
        return ConcurrencyException::forStaleAggregate(
            $aggregate::class,
            AggregateIdString::from($identifiers),
            $versionBeforeHandling,
            (int) $aggregate->newQueryWithoutScopes()->whereKey($aggregate->getKey())->value($versionColumn),
        );
    }

    private function writeVersionProperty(Model $aggregate, string $versionProperty, int $version): void
    {
        (fn () => $this->{$versionProperty} = $version)->call($aggregate);
    }

    private function versionColumnOf(string $aggregateClassName): ?string
    {
        return $this->aggregateDefinitionRegistry->getFor($aggregateClassName)->getAggregateVersionProperty();
    }
}
