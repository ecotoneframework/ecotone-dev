<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config\ProjectionBuilder;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * @internal
 */
class ProjectionEventHandlerConfiguration implements DefinedObject
{
    public function __construct(
        public string $channelName,
        public bool $doesItReturnsUserState,
    ) {
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            self::class,
            [
                $this->channelName,
                $this->doesItReturnsUserState,
            ],
        );
    }
}