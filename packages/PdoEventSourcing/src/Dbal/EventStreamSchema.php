<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\EventStore\Operator;

/**
 * licence BSD-3-Clause
 * code comes from https://github.com/prooph/pdo-event-store
 * (c) 2016-2025 Alexander Miertsch <kontakt@codeliner.ws>
 * (c) 2016-2025 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 */
interface EventStreamSchema
{
    /**
     * @return array<string>
     */
    public function createTableSql(string $tableName): array;

    public function dropTableSql(string $tableName): string;

    public function tableExists(Connection $connection, string $tableName): bool;

    public function quoteIdentifier(string $identifier): string;

    public function metadataFieldExpression(string $field, bool $comparedWithInteger): string;

    public function operatorSql(Operator $operator): string;

    public function booleanLiteral(bool $value): string;
}
