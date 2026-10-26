<?php

declare(strict_types=1);

namespace App\Ordering;

use Ecotone\Api\Attribute\Repository;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Modelling\StateStoredRepository;

#[Repository]
final class OrderRepository implements StateStoredRepository
{
    public function canHandle(string $aggregateClassName): bool
    {
        return $aggregateClassName === Order::class;
    }

    public function findBy(string $aggregateClassName, array $identifiers): ?object
    {
        return Order::query()->find(array_pop($identifiers));
    }

    public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
    {
        if (! $aggregate->exists) {
            $aggregate->save();

            return;
        }

        $saved = Order::query()
            ->whereKey($aggregate->getKey())
            ->where('version', $versionBeforeHandling)
            ->update($aggregate->getDirty());
        if ($saved === 0) {
            throw ConcurrencyException::forStaleAggregate(
                aggregateType: Order::class,
                aggregateId: $aggregate->getKey(),
                expectedVersion: (int) $versionBeforeHandling,
                currentVersion: (int) Order::query()->whereKey($aggregate->getKey())->value('version'),
            );
        }
        $aggregate->syncOriginal();
    }
}
