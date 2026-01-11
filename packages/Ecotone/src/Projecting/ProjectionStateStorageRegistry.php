<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use RuntimeException;

final class ProjectionStateStorageRegistry
{
    /**
     * @param ProjectionStateStorage[] $storages
     */
    public function __construct(
        private array $storages,
    ) {
    }

    public function getFor(string $projectionName): ProjectionStateStorage
    {
        foreach ($this->storages as $storage) {
            if ($storage->canHandle($projectionName)) {
                return $storage;
            }
        }

        throw new RuntimeException("No projection state storage found for projection: {$projectionName}");
    }
}

