<?php

namespace Ecotone\Messaging\Config\Container\Implementations;

use Ecotone\Messaging\Config\Container\ContainerHydrator;
use Ecotone\Messaging\Config\Container\ContainerImplementation;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Psr\Container\ContainerInterface;

class CachedContainerHydrator implements ContainerHydrator
{
    public function __construct(private string $containerClassName)
    {
    }

    public function create(ReferenceSearchService $referenceSearchService): ContainerInterface
    {
        $serviceCacheConfiguration = $referenceSearchService->get(ServiceCacheConfiguration::class);
        if (!\class_exists($this->containerClassName)) {
            require_once $serviceCacheConfiguration->getPath(). DIRECTORY_SEPARATOR . $this->containerClassName . ".php";
        }

        $container = new $this->containerClassName();
        $container->set(ContainerImplementation::EXTERNAL_REFERENCE_SEARCH_SERVICE_ID, $referenceSearchService);
        return $container;
    }
}