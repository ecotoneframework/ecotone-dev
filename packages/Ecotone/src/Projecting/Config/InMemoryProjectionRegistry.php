<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config;

use Ecotone\Projecting\ProjectingManager;
use Ecotone\Projecting\ProjectionRegistry;

class InMemoryProjectionRegistry implements ProjectionRegistry
{
    /**
     * @param array<string, ProjectingManager> $projectionManagers key is projection name
     */
    public function __construct(
        private array $projectionManagers,
    )
    {
    }

    public function has(string $id): bool
    {
        return isset($this->projectionManagers[$id]);
    }

    public function get(string $id): ProjectingManager
    {
        if (!$this->has($id)) {
            throw new \InvalidArgumentException(\sprintf('Projection with name "%s" does not exist', $id));
        }

        return $this->projectionManagers[$id];
    }
}