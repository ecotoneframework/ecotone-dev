<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Database\AutomaticTableInitializationSupport;
use Ecotone\Dbal\Database\AutomaticTableInitializationTrait;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\EventSourcing\Dbal\Tag\TaggedEventSchemaFactory;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Enterprise
 */
final class TagTableManager implements DbalTableManager
{
    use AutomaticTableInitializationTrait;

    public const FEATURE_NAME = 'event_tags';
    public const TAGGED_EVENTS_TABLE = 'ecotone_tagged_events';
    public const TAG_VERSIONS_TABLE = 'ecotone_tag_versions';

    public function __construct(
        private bool $isUsed,
        private bool $shouldAutoInitialize,
        private ?string $consoleInvocationPrefix = null,
    ) {
    }

    public function getFeatureName(): string
    {
        return self::FEATURE_NAME;
    }

    public function isUsed(): bool
    {
        return $this->isUsed;
    }

    public function getCreateTableSql(Connection $connection): string|array
    {
        $schema = TaggedEventSchemaFactory::for($connection);

        return [
            ...$schema->createTaggedEventsTableSql(self::TAGGED_EVENTS_TABLE),
            ...$schema->createTagVersionsTableSql(self::TAG_VERSIONS_TABLE),
        ];
    }

    public function getDropTableSql(Connection $connection): string
    {
        $schema = TaggedEventSchemaFactory::for($connection);

        return implode(";\n", [
            $schema->dropTableSql(self::TAGGED_EVENTS_TABLE),
            $schema->dropTableSql(self::TAG_VERSIONS_TABLE),
        ]);
    }

    public function createTable(Connection $connection): void
    {
        foreach ($this->getCreateTableSql($connection) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    public function dropTable(Connection $connection): void
    {
        $schema = TaggedEventSchemaFactory::for($connection);
        $connection->executeStatement($schema->dropTableSql(self::TAGGED_EVENTS_TABLE));
        $connection->executeStatement($schema->dropTableSql(self::TAG_VERSIONS_TABLE));
    }

    public function isInitialized(Connection $connection): bool
    {
        $schema = TaggedEventSchemaFactory::for($connection);

        return $schema->tableExists($connection, self::TAGGED_EVENTS_TABLE)
            && $schema->tableExists($connection, self::TAG_VERSIONS_TABLE);
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->isUsed, $this->shouldAutoInitialize, $this->consoleInvocationPrefix]);
    }

    public function getMissingTableInstructions(Connection $connection): string
    {
        return AutomaticTableInitializationSupport::isSupported($connection)
            ? MissingTableInstructions::build(
                self::FEATURE_NAME,
                self::TAGGED_EVENTS_TABLE . ', ' . self::TAG_VERSIONS_TABLE,
                $this->consoleInvocationPrefix
            )
            : MissingTableInstructions::buildForUnsupportedAutomaticInitialization(
                self::FEATURE_NAME,
                self::TAGGED_EVENTS_TABLE . ', ' . self::TAG_VERSIONS_TABLE,
                $this->consoleInvocationPrefix
            );
    }
}
