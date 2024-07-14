<?php

namespace Ecotone\EventSourcing\Config\InboundChannelAdapter;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
class ProjectionChannelAdapter implements DefinedObject
{
    public function run()
    {
        //        This is executed by channel adapter, which then follows to execute.
        //        It allows for intercepting messaging handling (e.g. transaction management).
        return true;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class);
    }
}
