<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config;

use Ecotone\Projecting\Dbal\DbalProjectionLifecycleStateStorage;
use Ecotone\Projecting\Dbal\DbalProjectionStateStorage;
use Ecotone\Projecting\InMemory\InMemoryProjectionLifecycleStateStorage;
use Ecotone\Projecting\InMemory\InMemoryProjectionStateStorage;

class ProjectingConfiguration
{
    public function __construct(
        public readonly string $projectionStateStorageReference,
        public readonly string $projectionLifecycleStateStorageReference,
    ) {
    }

    public static function createInMemory(): static {
        return new self(
            InMemoryProjectionStateStorage::class,
            InMemoryProjectionLifecycleStateStorage::class,
        );
    }

    public static function createDbal(): static {
        return new self(
            DbalProjectionStateStorage::class,
            DbalProjectionLifecycleStateStorage::class,
        );
    }

    public function withProjectionStateStorageReference(string $projectionStateStorageReference): static
    {
        return new self(
            $projectionStateStorageReference,
            $this->projectionLifecycleStateStorageReference,
        );
    }

    public function withProjectionLifecycleStateStorageReference(string $projectionLifecycleStateStorageReference): static
    {
        return new self(
            $this->projectionStateStorageReference,
            $projectionLifecycleStateStorageReference,
        );
    }
}