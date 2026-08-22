<?php

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;
use Ecotone\Enqueue\ReconnectableConnectionFactory;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Messaging\Scheduling\EcotoneClockInterface;
use Ecotone\Messaging\Scheduling\NativeClock;
use Exception;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use ReflectionMethod;

/**
 * licence Apache-2.0
 */
class DbalReconnectableConnectionFactory implements ReconnectableConnectionFactory
{
    public const CONNECTION_PROPERTIES = ['connection', '_conn'];

    private ConnectionFactory $connectionFactory;
    private EcotoneClockInterface $clock;

    public function __construct(ConnectionFactory $dbalConnectionFactory, ?EcotoneClockInterface $clock = null)
    {
        $this->connectionFactory = $dbalConnectionFactory;
        $this->clock = $clock ?? new NativeClock();
    }

    public function createContext(): Context
    {
        $context = $this->connectionFactory->createContext();
        if ($this->isDisconnected($context)) {
            $this->reconnect();
        }

        if ($context instanceof DbalContext) {
            $context->withClock($this->clock);
        }

        return $context;
    }

    public function getConnectionInstanceId(): string
    {
        return get_class($this->connectionFactory) . spl_object_id($this->connectionFactory);
    }

    public function getWrappedConnectionFactory(): ConnectionFactory
    {
        return $this->connectionFactory;
    }

    /**
     * @param Context|null|DbalContext $context
     * @return bool
     */
    public function isDisconnected(?Context $context): bool
    {
        if (! $context) {
            return false;
        }

        $connection = $context->getDbalConnection();
        $isConnected = $connection->isConnected();

        return ! $isConnected;
    }

    public function reconnect(): void
    {
        $connection = $this->getConnection();

        $reflectionMethod = new ReflectionMethod($connection, 'close');
        $reflectionMethod->invoke($connection);

        $reflectionMethod = new ReflectionMethod($connection, 'connect');
        $reflectionMethod->invoke($connection);
    }

    public function getConnection(): Connection
    {
        return self::getWrappedConnection($this->connectionFactory);
    }

    private function ping(Connection $connection): bool
    {
        try {
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @param EcotoneManagerRegistryConnectionFactory|Connection $connection
     */
    public static function getWrappedConnection(object $connection): Connection
    {
        if ($connection instanceof HeaderBasedMultiTenantConnectionFactory) {
            /** @var DbalContext $dbalConnection */
            $dbalConnection = $connection->createContext();

            return $dbalConnection->getDbalConnection();
        }

        if (
            $connection instanceof EcotoneManagerRegistryConnectionFactory
            || $connection instanceof AlreadyConnectedDbalConnectionFactory
        ) {
            return $connection->getConnection();
        } else {
            return $connection->establishConnection();
        }
    }
}
