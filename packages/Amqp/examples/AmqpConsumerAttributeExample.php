<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Examples;

use Ecotone\Api\Amqp\RabbitConsumer;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Attribute\QueryHandler;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
    #[RabbitConsumer('order_processor', 'order_queue')]
    public function processOrder(#[Payload] string $orderData): void
    {
        // Process the order
        $this->processedOrders[] = $orderData;

        // Your business logic here
        echo 'Processing order: ' . $orderData . "\n";
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
