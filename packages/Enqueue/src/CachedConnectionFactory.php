<?php

namespace Ecotone\Enqueue;

use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Consumer;
use Interop\Queue\Context;
use Interop\Queue\Destination;
use Interop\Queue\Producer;

/**
 * licence Apache-2.0
 */
class CachedConnectionFactory implements \Ecotone\Enqueue\ConnectionFactory
{
    private ReconnectableConnectionFactory $connectionFactory;
    private array $cachedContext = [];

    public function __construct(ReconnectableConnectionFactory $reconnectableConnectionFactory)
    {
        $this->connectionFactory = $reconnectableConnectionFactory;
    }

    public static function registerIn(Configuration $configuration, string $connectionKey, Definition $reconnectableConnectionFactory): Reference
    {
        $configuration->registerServiceDefinition(self::serviceId($connectionKey), new Definition(self::class, [$reconnectableConnectionFactory]));

        return Reference::to(self::serviceId($connectionKey));
    }

    public static function compileIn(MessagingContainerBuilder $builder, string $connectionKey, Definition $reconnectableConnectionFactory): Reference
    {
        if (! $builder->has(self::serviceId($connectionKey))) {
            $builder->register(self::serviceId($connectionKey), new Definition(self::class, [$reconnectableConnectionFactory]));
        }

        return Reference::to(self::serviceId($connectionKey));
    }

    public function createContext(): Context
    {
        $relatedTo = $this->getCurrentActiveConnection();
        $context = $this->cachedContext[$relatedTo] ?? null;

        if (! $context || $this->connectionFactory->isDisconnected($context)) {
            $this->cachedContext[$relatedTo] = $this->connectionFactory->createContext();
        }

        return $this->cachedContext[$relatedTo];
    }

    public function reconnect(): void
    {
        $this->connectionFactory->reconnect();
        $this->cachedContext = [];
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

    public function getWrappedConnectionFactory(): ConnectionFactory
    {
        return $this->connectionFactory->getWrappedConnectionFactory();
    }

    private static function serviceId(string $connectionKey): string
    {
        return self::class . '.' . $connectionKey;
    }

    private function getCurrentActiveConnection(): string
    {
        $connectionFactory = $this->getWrappedConnectionFactory();
        if (interface_exists(MultiTenantConnectionFactory::class) && $connectionFactory instanceof MultiTenantConnectionFactory) {
            return $connectionFactory->currentActiveTenant();
        }

        return 'default';
    }
}
