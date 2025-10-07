<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Enqueue\AmqpExt\AmqpConnectionFactory as AmqpExtConnectionFactory;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnectionFactory;
use Interop\Queue\ConnectionFactory;

/**
 * Helper class to detect and handle both AMQP extension and AMQP lib implementations
 * 
 * licence Apache-2.0
 */
class AmqpConnectionType
{
    public static function isAmqpExt(ConnectionFactory $connectionFactory): bool
    {
        return $connectionFactory instanceof AmqpExtConnectionFactory;
    }

    public static function isAmqpLib(ConnectionFactory $connectionFactory): bool
    {
        return $connectionFactory instanceof AmqpLibConnectionFactory;
    }

    /**
     * Get the default connection factory class name
     * For backward compatibility, this returns AmqpExt by default
     */
    public static function getDefaultConnectionFactoryClass(): string
    {
        return AmqpExtConnectionFactory::class;
    }

    /**
     * Get the connection factory class for streams
     * Streams require AmqpLib
     */
    public static function getStreamConnectionFactoryClass(): string
    {
        return AmqpLibConnectionFactory::class;
    }
}

