<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Dbal\Doctrine;

use Doctrine\DBAL\ParameterType;
use Ecotone\EventSourcingV2\EventStore\Dbal\Statement;

class DoctrineStatement implements Statement
{
    private ?\Doctrine\DBAL\Result $result = null;
    public function __construct(private \Doctrine\DBAL\Statement $doctrineStatement)
    {
    }

    public function execute(array $params = [], array $types = []): void
    {
        try {
            foreach ($params as $key => $value) {
                $doctrineType = $this->typeToDoctrineType($types[$key] ?? self::PARAM_STR);
                $this->doctrineStatement->bindValue(\is_string($key) ? $key : $key + 1, $value, $doctrineType);
            }
            $this->result = $this->doctrineStatement->executeQuery();
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            throw new \Ecotone\EventSourcingV2\EventStore\Dbal\DriverException($e->getCode(), $e);
        }
    }

    public function fetch(): array|false
    {
        try {
            return $this->result?->fetchAssociative() ?? false;
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            throw new \Ecotone\EventSourcingV2\EventStore\Dbal\DriverException($e->getCode(), $e);
        }
    }

    public function fetchColumn(int $columnNumber = 0): mixed
    {
        try {
            return $this->result?->fetchOne();
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            throw new \Ecotone\EventSourcingV2\EventStore\Dbal\DriverException($e->getCode(), $e);
        }
    }

    public function rowCount(): int
    {
        try {
            return $this->result?->rowCount() ?? 0;
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            throw new \Ecotone\EventSourcingV2\EventStore\Dbal\DriverException($e->getCode(), $e);
        }
    }

    private function typeToDoctrineType(int $type): int
    {
        return match ($type) {
            self::PARAM_STR => ParameterType::STRING,
            self::PARAM_INT => ParameterType::INTEGER,
            default => throw new \InvalidArgumentException("Unsupported type")
        };
    }
}