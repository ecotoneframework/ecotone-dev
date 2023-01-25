<?php

namespace Ecotone\Enqueue;

use Interop\Queue\ConnectionFactory;
use Interop\Queue\Consumer;
use Interop\Queue\Context;
use Interop\Queue\Destination;
use Interop\Queue\Producer;

class CachedConnectionFactory implements ConnectionFactory
{
    private static $instances = [];

    private ReconnectableConnectionFactory $connectionFactory;
    private ?Context $cachedContext = null;

    private function __construct(ReconnectableConnectionFactory $reconnectableConnectionFactory)
    {
        $this->connectionFactory = $reconnectableConnectionFactory;
    }

    public static function createFor(ReconnectableConnectionFactory $reconnectableConnectionFactory): self
    {
        if (! isset(self::$instances[$reconnectableConnectionFactory->getConnectionInstanceId()])) {
            self::$instances[$reconnectableConnectionFactory->getConnectionInstanceId()] = new self($reconnectableConnectionFactory);
        }

        return self::$instances[$reconnectableConnectionFactory->getConnectionInstanceId()];
    }

    public function createContext(): Context
    {
        if (! $this->cachedContext || $this->connectionFactory->isDisconnected($this->cachedContext)) {
            $this->cachedContext = $this->connectionFactory->createContext();
        }

        return $this->cachedContext;
    }

    public function getConsumer(Destination $destination): Consumer
    {
        return $this->createContext()->createConsumer($destination);
    }

    public function getProducer(): Producer
    {
        return $this->createContext()->createProducer();
    }

    public function getInnerConnectionFactory(): ConnectionFactory
    {
        return $this->connectionFactory;
    }
}
