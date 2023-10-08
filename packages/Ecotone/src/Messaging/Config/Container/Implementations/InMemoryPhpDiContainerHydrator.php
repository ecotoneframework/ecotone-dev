<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use DI\ContainerBuilder;
use Ecotone\Messaging\Config\Container\ContainerHydrator;
use Ecotone\Messaging\Config\Container\ContainerImplementation;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;

class InMemoryPhpDiContainerHydrator implements ContainerHydrator
{
    public function __construct(private ContainerBuilder $builder)
    {
    }

    public function create(ReferenceSearchService $referenceSearchService): ContainerInterface
    {
        $container = $this->builder->build();
        $container->set(ContainerImplementation::EXTERNAL_REFERENCE_SEARCH_SERVICE_ID, $referenceSearchService);
        return $container;
    }

    public function __serialize(): array
    {
        throw new InvalidArgumentException('InMemoryPhpDiContainerHydrator cannot be serialized');
    }
}
