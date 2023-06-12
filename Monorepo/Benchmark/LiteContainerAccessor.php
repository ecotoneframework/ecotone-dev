<?php

namespace Monorepo\Benchmark;

use Ecotone\Messaging\Config\MessagingSystem;
use Psr\Container\ContainerInterface;

class LiteContainerAccessor implements ContainerInterface
{
    public function __construct(private MessagingSystem $messagingSystem)
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