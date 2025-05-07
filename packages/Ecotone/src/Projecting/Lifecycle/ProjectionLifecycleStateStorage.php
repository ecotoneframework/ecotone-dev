<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Lifecycle;

interface ProjectionLifecycleStateStorage
{
    public function init(string $projectionName): bool;
    public function delete(string $projectionName): bool;
}