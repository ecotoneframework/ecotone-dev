<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Modelling\AggregateFlow;

use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\ResolvedAggregate;

class AllAggregateRepository implements AggregateRepository
{
    /**
     * @param AggregateRepository[] $aggregateRepositories
     */
    public function __construct(private array $aggregateRepositories)
    {
        Assert::allInstanceOfType($aggregateRepositories, AggregateRepository::class);
    }

    public function canHandle(string $aggregateClassName): bool
    {
        foreach ($this->aggregateRepositories as $aggregateRepository) {
            if ($aggregateRepository->canHandle($aggregateClassName)) {
                return true;
            }
        }

        return false;
    }

    public function findBy(string $aggregateClassName, array $identifiers): ?ResolvedAggregate
    {
        foreach ($this->aggregateRepositories as $aggregateRepository) {
            if ($aggregateRepository->canHandle($aggregateClassName)) {
                return $aggregateRepository->findBy($aggregateClassName, $identifiers);
            }
        }

        return null;
    }

    public function save(ResolvedAggregate $aggregate, array $metadata, ?int $versionBeforeHandling): void
    {
        foreach ($this->aggregateRepositories as $aggregateRepository) {
            if ($aggregateRepository->canHandle($aggregate->getAggregateClassName())) {
                $aggregateRepository->save($aggregate, $metadata, $versionBeforeHandling);
            }
        }
    }
}
{

}