<?php

declare(strict_types=1);

namespace Ecotone\Api\Messaging;

use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class DestinationDoesNotExistException extends ConfigurationException
{
    public function __construct(
        string $message,
        int $code = self::CONFIGURATION_EXCEPTION,
        public readonly string $channelName = '',
        public readonly string $destination = '',
        public readonly string $connection = '',
    ) {
        parent::__construct($message, $code);
    }

    public static function forChannel(string $message, string $channelName, string $destination, string $connection): self
    {
        return new self($message, self::CONFIGURATION_EXCEPTION, $channelName, $destination, $connection);
    }
}
