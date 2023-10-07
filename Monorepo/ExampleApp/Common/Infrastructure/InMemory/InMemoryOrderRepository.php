<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure\InMemory;

use Ecotone\Modelling\StandardRepository;
use Monorepo\ExampleApp\Common\Domain\Order\Order;
use Monorepo\ExampleApp\Common\Domain\Order\OrderRepository;
use Ramsey\Uuid\UuidInterface;

final class InMemoryOrderRepository implements StandardRepository
{
    /** @var Order[] */
    private array $orders;

    public function __construct() {}

    public function canHandle(string $aggregateClassName): bool
    {
        return $aggregateClassName === Order::class;
    }

    public function findBy(string $aggregateClassName, array $identifiers): ?object
    {
        $identifier = array_pop($identifiers);

        if (isset($this->orders[$identifier])) {
            return $this->orders[$identifier];
        }

        return null;
    }

    public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
    {
        $identifier = array_pop($identifiers);

        $this->orders[$identifier] = $aggregate;
    }

    public function getBy(UuidInterface $orderId): Order
    {
        if (!isset($this->orders[$orderId->toString()])) {
            throw new \RuntimeException(sprintf("User with id %s not found", $orderId->toString()));
        }

        return $this->orders[$orderId->toString()];
    }
}