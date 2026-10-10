<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\Messaging\Config\ConfigurationException;

/**
 * licence Apache-2.0
 */
final class MissingEventStreamTable
{
    public function __construct(private ?string $consoleInvocationPrefix = null)
    {
    }

    public function exceptionFor(string $tableName, ?string $connectionReferenceName = null): ConfigurationException
    {
        return ConfigurationException::create(
            MissingTableInstructions::build(EventStreamTableManager::FEATURE_NAME, $tableName, $this->consoleInvocationPrefix, $connectionReferenceName)
        );
    }
}
