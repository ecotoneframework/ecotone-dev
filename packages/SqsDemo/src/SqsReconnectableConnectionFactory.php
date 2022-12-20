<?php

declare(strict_types=1);

namespace Test\SqsDemo;

use Ecotone\Enqueue\ReconnectableConnectionFactory;
use Enqueue\Sqs\SqsConnectionFactory;
use Interop\Queue\Context;

final class SqsReconnectableConnectionFactory implements ReconnectableConnectionFactory
{
    public function __construct(private SqsConnectionFactory $connectionFactory)
    {}

    public function createContext(): Context
    {
        return $this->connectionFactory->createContext();
    }

    public function isDisconnected(?Context $context): bool
    {
        $this->createContext()->close();;
    }

    public function reconnect(): void
    {
        return;
    }

    public function getConnectionInstanceId(): int
    {
        return spl_object_id($this->connectionFactory);
    }
}