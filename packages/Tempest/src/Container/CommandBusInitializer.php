<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Container;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Modelling\CommandBus;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;

/**
 * licence Apache-2.0
 */
final class CommandBusInitializer implements Initializer
{
    #[Singleton]
    public function initialize(Container $container): CommandBus
    {
        /** @var ConfiguredMessagingSystem $messagingSystem */
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);
        
        return $messagingSystem->getGatewayByName(CommandBus::class);
    }
}
