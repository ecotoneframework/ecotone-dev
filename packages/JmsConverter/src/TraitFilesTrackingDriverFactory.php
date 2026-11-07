<?php

declare(strict_types=1);

namespace Ecotone\JMSConverter;

use JMS\Serializer\Builder\DriverFactoryInterface;
use Metadata\Driver\DriverInterface;

/**
 * licence Apache-2.0
 */
final class TraitFilesTrackingDriverFactory implements DriverFactoryInterface
{
    public function __construct(private DriverFactoryInterface $driverFactory)
    {
    }

    public function createDriver(array $metadataDirs, $annotationReader = null): DriverInterface
    {
        return new TraitFilesTrackingDriver($this->driverFactory->createDriver($metadataDirs, $annotationReader));
    }
}
