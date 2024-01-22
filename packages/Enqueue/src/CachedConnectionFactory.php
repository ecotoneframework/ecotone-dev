<?php

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConnectionFactory;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Consumer;
use Interop\Queue\Context;
use Interop\Queue\Destination;
use Interop\Queue\Producer;

class CachedConnectionFactory implements ConnectionFactory
{
    private static $instances = [];

    private ReconnectableConnectionFactory $connectionFactory;
    private array $cachedContext = [];

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
        $relatedTo = $this->relatedTo();
        $context = isset($this->cachedContext[$relatedTo]) ? $this->cachedContext[$relatedTo] : null;

        if (!$context || $this->connectionFactory->isDisconnected($context)) {
            $this->cachedContext[$relatedTo] = $this->connectionFactory->createContext();
        }

        return $this->cachedContext[$relatedTo];
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

    private function relatedTo(): string
    {
        $connectionFactory = $this->connectionFactory->getConnectionFactory();
        if ($connectionFactory instanceof MultiTenantConnectionFactory) {
            return $connectionFactory->currentActiveTenant();
        }

        return 'default';
    }
}
