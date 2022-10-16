<?php

namespace Ecotone\Lite;

use Psr\Container\ContainerInterface;

interface ContainerInterfaceWithSet extends ContainerInterface
{
    public function setService(string $referenceName, object $service): void;
}
