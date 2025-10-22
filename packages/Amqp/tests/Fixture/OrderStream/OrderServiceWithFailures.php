<?php

namespace Test\Ecotone\Amqp\Fixture\OrderStream;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use RuntimeException;

#[Asynchronous('orders')]
/**
 * licence Apache-2.0
 */
class OrderServiceWithFailures
{
    /**
     * @var string[]
     */
    private array $orders = [];
    
    /**
     * @var int[]
     */
    private array $attemptCounts = [];

    #[CommandHandler('order.register', 'order.register.endpoint')]
    public function register(string $order): void
    {
        // Track attempt count for this order
        if (!isset($this->attemptCounts[$order])) {
            $this->attemptCounts[$order] = 0;
        }
        $this->attemptCounts[$order]++;
        
        // Fail on first attempt for orders starting with "fail_"
        if (str_starts_with($order, 'fail_') && $this->attemptCounts[$order] === 1) {
            throw new RuntimeException("Simulated failure for order: {$order}");
        }
        
        $this->orders[] = $order;
    }

    #[QueryHandler('order.getOrders')]
    public function getRegisteredOrders(): array
    {
        return $this->orders;
    }
    
    #[QueryHandler('order.getAttemptCount')]
    public function getAttemptCount(string $order): int
    {
        return $this->attemptCounts[$order] ?? 0;
    }
    
    #[QueryHandler('order.getAllAttemptCounts')]
    public function getAllAttemptCounts(): array
    {
        return $this->attemptCounts;
    }
}

