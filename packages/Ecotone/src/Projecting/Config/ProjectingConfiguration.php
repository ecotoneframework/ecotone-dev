<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config;

use Ecotone\Projecting\Dbal\DbalProjectionStateStorage;
use Ecotone\Projecting\InMemory\InMemoryProjectionStateStorage;

class ProjectingConfiguration
{
    public function __construct(
        public readonly string $projectionStateStorageReference,
    ) {
    }

    public static function createInMemory(): static {
        return new self(InMemoryProjectionStateStorage::class);
    }

    public static function createDbal(): static {
        return new self(DbalProjectionStateStorage::class);
    }
}