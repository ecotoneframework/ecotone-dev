<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Examples;

use Ecotone\Amqp\Attribute\AmqpConsumer;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Enterprise
 * 
 * Example demonstrating the usage of AmqpConsumer attribute
 * for consuming messages from AMQP queues.
 */
final class AmqpConsumerAttributeExample
{
    /** @var string[] */
    private array $processedOrders = [];

    /**
     * This method will consume messages from the 'order_queue' AMQP queue.
     * The endpointId 'order_processor' is used to identify this consumer.
     */
    #[AmqpConsumer('order_processor', 'order_queue')]
    public function processOrder(#[Payload] string $orderData): void
    {
        // Process the order
        $this->processedOrders[] = $orderData;
        
        // Your business logic here
        echo "Processing order: " . $orderData . "\n";
    }

    /**
     * Query handler to retrieve processed orders for testing/monitoring
     */
    #[QueryHandler('orders.getProcessed')]
    public function getProcessedOrders(): array
    {
        return $this->processedOrders;
    }
}
