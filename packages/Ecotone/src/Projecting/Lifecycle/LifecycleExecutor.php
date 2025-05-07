<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Lifecycle;

interface LifecycleExecutor
{
    public function init(string $projectionName): void;

    public function reset(string $projectionName): void;

    public function delete(string $projectionName): void;
}