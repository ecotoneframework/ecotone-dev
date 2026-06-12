<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\DependencyInjection;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\SymfonyContainer\EcotoneContainer;
use Ecotone\SymfonyContainer\EcotoneSymfonyContainerFactory;
use Psr\Container\ContainerInterface;

/**
 * licence Apache-2.0
 */
final class EcotoneContainerLoader
{
    public static function load(string $cacheDirectory, ContainerInterface $applicationContainer): EcotoneContainer
    {
        return EcotoneSymfonyContainerFactory::loadCached(
            new ServiceCacheConfiguration($cacheDirectory, true),
            $applicationContainer,
        ) ?? throw ConfigurationException::create("Ecotone compiled container is missing in {$cacheDirectory}. Please warm up the cache again (e.g. bin/console cache:clear).");
    }
}
