<?php

namespace Monorepo\Benchmark;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Psr\Container\ContainerInterface;

class LiteContainerAccessor implements ContainerInterface
{
    public function __construct(private ConfiguredMessagingSystem $messagingSystem)
    {
    }

    public function get(string $id)
    {
        return $this->messagingSystem->getServiceFromContainer($id);
    }

    public function has(string $id): bool
    {
        throw new \Exception("Not implemented");
    }
}