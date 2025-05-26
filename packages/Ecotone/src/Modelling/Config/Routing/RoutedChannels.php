<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Modelling\Config\Routing;

class RoutedChannels
{
    public function __construct(public readonly string $router, public readonly BusRoutingConfigBuilder $busRoutingConfigBuilder)
    {
    }
}