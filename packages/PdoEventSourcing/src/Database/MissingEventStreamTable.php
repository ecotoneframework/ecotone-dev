<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\Messaging\Config\ConfigurationException;

use function implode;

/**
 * licence Apache-2.0
 */
final class MissingEventStreamTable
{
    public function __construct(private ?string $consoleInvocationPrefix = null)
    {
    }

    /**
     * @param string[] $declaredStreamNames
     */
    public function undeclaredStreamReadByProjectionException(string $projectionName, string $streamName, array $declaredStreamNames): ConfigurationException
    {
        return ConfigurationException::create(
            "Projection `{$projectionName}` reads event stream `{$streamName}` through #[FromStream('{$streamName}')], " . $this->undeclaredStreamInstructions($streamName, $declaredStreamNames)
        );
    }

    /**
     * @param string[] $declaredStreamNames
     */
    public function undeclaredStreamAppendedToException(string $streamName, array $declaredStreamNames): ConfigurationException
    {
        return ConfigurationException::create(
            "Event stream `{$streamName}` is appended to, " . $this->undeclaredStreamInstructions($streamName, $declaredStreamNames)
        );
    }

    public function exceptionFor(string $tableName, ?string $connectionReferenceName = null): ConfigurationException
    {
        return ConfigurationException::create(
            MissingTableInstructions::build(EventStreamTableManager::FEATURE_NAME, $tableName, $this->consoleInvocationPrefix, $connectionReferenceName)
        );
    }

    /**
     * @param string[] $declaredStreamNames
     */
    private function undeclaredStreamInstructions(string $streamName, array $declaredStreamNames): string
    {
        $setupCommand = ($this->consoleInvocationPrefix === null ? '' : $this->consoleInvocationPrefix . ' ') . 'ecotone:migration:database:setup --initialize';

        return 'but nothing declares that stream, so no migration will ever create its table: ecotone:migration:database:setup creates the tables of the default stream and of every stream a #[Stream] attribute declares. '
            . "Declare it with #[Stream('{$streamName}')] on the class that owns its events - an event-sourced aggregate, the projection that emits into it, or any class Ecotone scans - "
            . "and create its table with {$setupCommand} (in an EcotoneLite flow test, \$ecotone->setupDatabaseTables()). "
            . 'Or use a declared stream: ' . implode(', ', $declaredStreamNames) . '.';
    }
}
