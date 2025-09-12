<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config;

use Ecotone\Messaging\Config\Container\CompilableBuilder;

interface ProjectionComponentBuilder extends CompilableBuilder
{
    public function canHandle(string $projectionName, string $component): bool;
}