<?php

namespace Ecotone\EventSourcing\Prooph;

use Prooph\EventStore\Projection\ReadModel;

/**
 * licence Apache-2.0
 */
class ProophReadModel implements ReadModel
{
    public function __construct()
    {
    }

    public function init(): void
    {
    }

    public function isInitialized(): bool
    {
        return false;
    }

    public function reset(): void
    {
    }

    public function delete(): void
    {
    }

    public function stack(string $operation, ...$args): void
    {
    }

    public function persist(): void
    {
    }
}
