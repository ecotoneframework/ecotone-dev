<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;

/**
 * licence Apache-2.0
 */
final class HttpReconnectableConnectionFactory implements ReconnectableConnectionFactory
{
    public function __construct(private ConnectionFactory $connectionFactory)
    {
    }

    public function createContext(): Context
    {
        return $this->connectionFactory->createContext();
    }

    public function isDisconnected(?Context $context): bool
    {
        return false;
    }

    public function reconnect(): void
    {
    }

    public function getConnectionInstanceId(): string
    {
        return get_class($this->connectionFactory) . spl_object_id($this->connectionFactory);
    }

    public function getWrappedConnectionFactory(): ConnectionFactory
    {
        return $this->connectionFactory;
    }
}
