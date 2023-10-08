<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\ReferenceSearchService;
use Psr\Container\ContainerInterface;

interface ContainerHydrator
{
    public function create(ReferenceSearchService $referenceSearchService): ContainerInterface;
}
