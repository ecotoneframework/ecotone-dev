<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

/**
 * Reference to a ProjectionStateStorage service registered in the container.
 * Event sourcing modules register their ProjectionStateStorage implementations as services
 * and provide this reference so ProjectionStateStorageRegistryModule can collect them.
 */
class ProjectionStateStorageReference
{
    /**
     * @param string $referenceName
     * @param string[] $projectionNames
     */
    public function __construct(
        private string $referenceName,
        private array $projectionNames,
    ) {
    }

    public function getReferenceName(): string
    {
        return $this->referenceName;
    }

    /**
     * @return string[]
     */
    public function getProjectionNames(): array
    {
        return $this->projectionNames;
    }
}

