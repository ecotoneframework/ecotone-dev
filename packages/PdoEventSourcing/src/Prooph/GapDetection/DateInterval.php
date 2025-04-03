<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\GapDetection;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
final class DateInterval implements DefinedObject
{
    public function __construct(private string $duration)
    {
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->duration]);
    }

    public function build(): \DateInterval
    {
        return new \DateInterval($this->duration);
    }
}
