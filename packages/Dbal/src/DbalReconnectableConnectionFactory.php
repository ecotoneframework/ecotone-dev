<?php

namespace Ecotone\Dbal;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\MultiTenant\HeaderBasedMultiTenantConnectionFactory;
use Ecotone\Enqueue\ReconnectableConnectionFactory;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Enqueue\Dbal\DbalContext;
use Exception;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use ReflectionClass;

/**
 * licence Apache-2.0
 */
class DbalReconnectableConnectionFactory implements ReconnectableConnectionFactory
{
    public const CONNECTION_PROPERTIES = ['connection', '_conn'];

    private ConnectionFactory $connectionFactory;

    public function __construct(ConnectionFactory $dbalConnectionFactory)
    {
        $this->connectionFactory = $dbalConnectionFactory;
    }

    public function createContext(): Context
    {
        $context = $this->connectionFactory->createContext();
        if ($this->isDisconnected($context)) {
            $this->reconnect();
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
        $isConnected = $connection->isConnected() && $this->ping($connection);

        return ! $isConnected;
    }

    public function reconnect(): void
    {
        $connection = $this->getConnection();

        if ($connection) {
            // In DBAL 4.x, close() and connect() are protected, so we need a different approach
            try {
                // Force a new connection by getting the native connection after clearing internal state
                $reflectionClass = new \ReflectionClass($connection);

                // Try to find the connection property - it might be '_conn' or 'connection' depending on DBAL version
                $connPropertyName = null;
                foreach (self::CONNECTION_PROPERTIES as $propertyName) {
                    if ($reflectionClass->hasProperty($propertyName)) {
                        $connPropertyName = $propertyName;
                        break;
                    }
                }

                if ($connPropertyName) {
                    $connectedProperty = $reflectionClass->getProperty($connPropertyName);
                    $connectedProperty->setAccessible(true);
                    $connectedProperty->setValue($connection, null);
                }

                // This will force a new connection
                $connection->getNativeConnection();
            } catch (\Exception $e) {
                // Best effort approach - if reflection fails, try another method
                try {
                    // For DBAL 4.x, we can try to close and reopen by nullifying our reference
                    // and getting a fresh connection
                    $this->connectionFactory->close();
                    $this->connectionFactory->createContext();
                } catch (\Exception $innerException) {
                    // We've tried our best
                }
            }
        }
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
            $reflectionClass   = new ReflectionClass($connection);
            $method = $reflectionClass->getMethod('establishConnection');
            $method->setAccessible(true);
            $method->invoke($connection);

            foreach ($reflectionClass->getProperties() as $property) {
                foreach (self::CONNECTION_PROPERTIES as $connectionPropertyName) {
                    if ($property->getName() === $connectionPropertyName) {
                        $connectionProperty = $reflectionClass->getProperty($connectionPropertyName);
                        $connectionProperty->setAccessible(true);
                        /** @var Connection $connection */
                        return $connectionProperty->getValue($connection);
                    }
                }
            }

            throw InvalidArgumentException::create('Did not found connection property in ' . $reflectionClass->getName());
        }
    }
}
