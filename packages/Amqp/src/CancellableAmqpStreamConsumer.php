<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

/**
 * Interface for AMQP Stream consumers that can be cancelled and restarted
 * 
 * This allows acknowledgement callbacks to trigger consumer restart for scenarios
 * like message release/retry where the consumer needs to restart from a specific offset.
 * 
 * licence Apache-2.0
 */
interface CancellableAmqpStreamConsumer
{
    /**
     * Cancel the current stream consumer
     * 
     * This stops the current consumer and clears any buffered messages.
     * The next call to receiveWithTimeout() will restart the consumer
     * from the last committed position.
     * 
     * @return void
     */
    public function cancelStreamConsumer(): void;
}

