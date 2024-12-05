<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Dbal\Pdo;

use Ecotone\EventSourcingV2\EventStore\Dbal\DriverException;
use Ecotone\EventSourcingV2\EventStore\Dbal\Statement;

class PdoStatement implements Statement
{
    public function __construct(
        private \PDOStatement $pdoStatement
    ) {
    }


    public function execute(array $params = [], array $types = []): void
    {
        try {
            foreach ($params as $key => $value) {
                $pdoType = $this->typeToPdoType($types[$key] ?? self::PARAM_STR);
                $this->pdoStatement->bindValue(\is_string($key) ? $key : $key + 1, $value, $pdoType);
            }
            $this->pdoStatement->execute();
        } catch (\PDOException $e) {
            throw new DriverException($e->errorInfo[1] ?? 0, $e);
        }
    }

    public function fetch(): array|false
    {
        try {
            return $this->pdoStatement->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new DriverException($e->errorInfo[1] ?? 0, $e);
        }
    }

    public function fetchColumn(int $columnNumber = 0): mixed
    {
        try {
            return $this->pdoStatement->fetchColumn($columnNumber);
        } catch (\PDOException $e) {
            throw new DriverException($e->errorInfo[1] ?? 0, $e);
        }
    }

    public function rowCount(): int
    {
        try {
            return $this->pdoStatement->rowCount();
        } catch (\PDOException $e) {
            throw new DriverException($e->errorInfo[1] ?? 0, $e);
        }
    }

    private function typeToPdoType(int $type): int
    {
        return match ($type) {
            self::PARAM_STR => \PDO::PARAM_STR,
            self::PARAM_INT => \PDO::PARAM_INT,
            default => throw new \InvalidArgumentException("Unsupported type")
        };
    }
}