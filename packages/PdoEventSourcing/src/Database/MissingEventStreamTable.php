<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Database\AutomaticTableInitializationSupport;
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

    public function exceptionFor(Connection $connection, string $tableName): ConfigurationException
    {
        return ConfigurationException::create(
            AutomaticTableInitializationSupport::isSupported($connection)
                ? MissingTableInstructions::build(EventStreamTableManager::FEATURE_NAME, $tableName, $this->consoleInvocationPrefix)
                : MissingTableInstructions::buildForUnsupportedAutomaticInitialization(EventStreamTableManager::FEATURE_NAME, $tableName, $this->consoleInvocationPrefix)
        );
    }
}
