<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Tempest\Discovery\DiscoveryLocation;

final class EcotoneTempestConfiguration
{
    public static function getDiscoveryPath(): DiscoveryLocation
    {
        return new DiscoveryLocation('Ecotone\\Tempest\\', __DIR__);
    }
}