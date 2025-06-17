<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Container;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Modelling\QueryBus;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;

/**
 * licence Apache-2.0
 */
final class QueryBusInitializer implements Initializer
{
    #[Singleton]
    public function initialize(Container $container): QueryBus
    {
        /** @var ConfiguredMessagingSystem $messagingSystem */
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);
        
        return $messagingSystem->getGatewayByName(QueryBus::class);
    }
}
