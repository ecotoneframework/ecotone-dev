<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\EventSourcing\Dbal\Tag\TaggedEventSchemaFactory;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class TagTableManager implements DbalTableManager
{
    public const FEATURE_NAME = 'event_tags';
    public const TAGGED_EVENTS_TABLE = 'ecotone_tagged_events';
    public const TAG_VERSIONS_TABLE = 'ecotone_tag_versions';

    public function __construct(
        private bool $isUsed,
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
        return new Definition(self::class, [$this->isUsed, $this->consoleInvocationPrefix]);
    }

    public function getMissingTableInstructions(): string
    {
        return MissingTableInstructions::build(
            self::FEATURE_NAME,
            self::TAGGED_EVENTS_TABLE . ', ' . self::TAG_VERSIONS_TABLE,
            $this->consoleInvocationPrefix
        );
    }
}
