<?php

namespace Test\Ecotone\Messaging\Fixture\Behat\Ordering;

use Ecotone\Messaging\Support\Assert;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;

/**
 * Class OrderProcessor
 * @package Test\Ecotone\Messaging\Fixture\Behat\Ordering
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class OrderProcessor
{
    public function processOrder(Order $order): OrderConfirmation
    {
        if (! $this->isCorrectOrder($order)) {
            throw new RuntimeException('Order is not correct!');
        }

        return OrderConfirmation::fromOrder($order);
    }

    /**
     * @param Uuid[] $ids
     * @return OrderConfirmation[]|array
     * @throws \Ecotone\Messaging\MessagingException
     */
    public function buyMultiple(array $ids): array
    {
        Assert::allInstanceOfType($ids, UuidInterface::class);
        $orders = [];
        foreach ($ids as $id) {
            $orders[] = OrderConfirmation::createFromUuid($id);
        }

        return $orders;
    }

    /**
     * @param UuidInterface $id
     * @return OrderConfirmation
     */
    public function buyByName(UuidInterface $id): OrderConfirmation
    {
        return OrderConfirmation::createFromUuid($id);
    }

    private function isCorrectOrder(Order $order): bool
    {
        return $order->getProductName() === 'correct';
    }
}
