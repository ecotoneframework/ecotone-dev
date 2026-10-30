<?php

namespace Ecotone\Laravel;

use Closure;
use Ecotone\Api\Laravel\EloquentSaveCancelled;
use Ecotone\Api\Laravel\EloquentVersionNotIncreased;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Modelling\StateStoredRepository;
use Ecotone\Messaging\Handler\Enricher\PropertyPath;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionRegistry;
use Ecotone\Modelling\AggregateIdString;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * licence Apache-2.0
 */
class EloquentRepository implements StateStoredRepository
{
    private const SAVEPOINT = 'ecotone_eloquent_save';

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
        $aggregateDefinition = $this->aggregateDefinitionRegistry->getFor($aggregate::class);
        $versionColumn = $aggregateDefinition->getAggregateVersionProperty();
        if ($versionColumn === null) {
            $aggregate->save();

            return;
        }

        $versionIncreasedByEcotone = $aggregateDefinition->isAggregateVersionAutomaticallyIncreased();
        if ($aggregate->exists && ($versionBeforeHandling === null || ! $versionIncreasedByEcotone)) {
            $versionBeforeHandling = (int) $aggregate->getOriginal($versionColumn);
            if ($versionIncreasedByEcotone) {
                $this->writeVersionProperty($aggregate, $versionColumn, $versionBeforeHandling + 1);
            }
        }
        $versionAfterHandling = $this->propertyReaderAccessor->getPropertyValue(PropertyPath::createWith($versionColumn), $aggregate);
        if (! $versionIncreasedByEcotone && $versionAfterHandling <= (int) $versionBeforeHandling) {
            throw EloquentVersionNotIncreased::for($aggregate::class, AggregateIdString::from($identifiers), (int) $versionBeforeHandling, $versionAfterHandling);
        }
        $aggregate->setAttribute($versionColumn, $versionAfterHandling);

        try {
            $this->asOneUnit(
                $aggregate->getConnection(),
                fn () => $this->claimVersionAndSave($identifiers, $aggregate, $versionColumn, (int) $versionBeforeHandling, $versionAfterHandling)
            );
        } catch (UniqueConstraintViolationException $uniqueConstraintViolation) {
            if ($aggregate->exists || ! $aggregate->newQueryWithoutScopes()->whereKey($this->storedKeyOf($aggregate))->exists()) {
                throw $uniqueConstraintViolation;
            }

            throw $this->staleAggregate($identifiers, $aggregate, $versionColumn, (int) $versionBeforeHandling);
        }
    }

    private function asOneUnit(Connection $connection, Closure $work): void
    {
        if ($connection->transactionLevel() > 0 || ! $connection->getPdo()->inTransaction()) {
            $connection->transaction($work);

            return;
        }

        $connection->getPdo()->exec($connection->getQueryGrammar()->compileSavepoint(self::SAVEPOINT));
        try {
            $work();
        } catch (Throwable $exception) {
            $connection->getPdo()->exec($connection->getQueryGrammar()->compileSavepointRollBack(self::SAVEPOINT));

            throw $exception;
        }
    }

    private function claimVersionAndSave(array $identifiers, Model $aggregate, string $versionColumn, int $versionBeforeHandling, int $versionAfterHandling): void
    {
        if ($aggregate->exists) {
            $this->claimVersion($identifiers, $aggregate, $versionColumn, $versionBeforeHandling, $versionAfterHandling);
        }

        if ($aggregate->save() === false) {
            throw EloquentSaveCancelled::for($aggregate::class, AggregateIdString::from($identifiers));
        }
    }

    private function claimVersion(array $identifiers, Model $aggregate, string $versionColumn, int $versionBeforeHandling, int $versionAfterHandling): void
    {
        $claimed = $aggregate->newQueryWithoutScopes()
            ->whereKey($this->storedKeyOf($aggregate))
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
            (int) $aggregate->newQueryWithoutScopes()->whereKey($this->storedKeyOf($aggregate))->value($versionColumn),
        );
    }

    private function storedKeyOf(Model $aggregate): mixed
    {
        return $aggregate->getOriginal($aggregate->getKeyName()) ?? $aggregate->getKey();
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
