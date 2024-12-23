<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Dbal;

interface Statement
{
    public const PARAM_STR = 1;
    public const PARAM_INT = 2;

    public function execute(array $params = [], array $types = []): void;
    public function fetch(): array|false;
    public function fetchColumn(int $columnNumber = 0): mixed;

    public function rowCount(): int;
}