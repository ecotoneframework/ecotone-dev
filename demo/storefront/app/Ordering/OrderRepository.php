<?php

declare(strict_types=1);

namespace App\Ordering;

use DomainException;
use Ecotone\Api\Attribute\Repository;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Modelling\StateStoredRepository;
use Illuminate\Database\UniqueConstraintViolationException;

#[Repository]
final class OrderRepository implements StateStoredRepository
{
    private const ONE_OPEN_ORDER_PER_CUSTOMER_AND_CONCERT = 'orders_one_open_order_per_customer_and_concert';

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
            $this->insert($aggregate);

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

    private function insert(Order $order): void
    {
        try {
            $order->save();
        } catch (UniqueConstraintViolationException $violation) {
            if (! str_contains($violation->getMessage(), self::ONE_OPEN_ORDER_PER_CUSTOMER_AND_CONCERT)) {
                throw $violation;
            }

            throw new DomainException(sprintf(
                'Customer %s already has an open order for concert %s, started by another request while this StartOrder ran, so StartOrder cannot start another: a customer has one open order per concert. Find that order with the customer.open_order query and hold more seats on it, or pay for it or release its seats before starting a new one.',
                $order->customer_id,
                $order->concert_id,
            ));
        }
    }
}
