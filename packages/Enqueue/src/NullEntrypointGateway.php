<?php

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;

class NullEntrypointGateway implements InboundChannelAdapterEntrypoint, DefinedObject
{
    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    public function executeEntrypoint($data): void
    {
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class);
    }
}
