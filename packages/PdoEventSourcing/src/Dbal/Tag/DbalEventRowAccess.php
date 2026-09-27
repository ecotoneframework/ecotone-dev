<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;
use Ecotone\Modelling\Event;

/**
 * The DCB tag collaborator needs to write/read plain event rows and apply the
 * open-core projection-invariant/legacy-constraint rules that have nothing to
 * do with tags -- this is DbalEventStore's own core behaviour, exposed back to
 * the collaborator instead of duplicated inside it.
 *
 * licence Apache-2.0
 */
interface DbalEventRowAccess
{
    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    public function convertToRow(object|array $eventToConvert): array;

    public function convertToEvent(array $row, bool $deserialize): Event;

    public function assertProjectionInvariant(string $streamName, string $eventName, object|array $eventToConvert): void;

    /**
     * @param array<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows
     */
    public function insertEventRows(Connection $connection, EventStreamSchema $schema, string $tableName, array $rows): void;

    public function tagTableContextKeyFor(string $streamName): string;

    public function isAutomaticTableInitializationEnabled(): bool;

    public function consoleInvocationPrefix(): ?string;
}