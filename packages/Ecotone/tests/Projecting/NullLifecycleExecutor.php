<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\Projecting\Lifecycle\LifecycleExecutor;

class NullLifecycleExecutor implements LifecycleExecutor
{

    public function init(string $projectionName): void
    {
    }

    public function reset(string $projectionName): void
    {
    }

    public function delete(string $projectionName): void
    {
    }
}