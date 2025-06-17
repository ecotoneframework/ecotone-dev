<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\QueryBus;
use Ecotone\Tempest\Configuration\TempestConfigurationVariableService;
use Ecotone\Tempest\Container\CommandBusInitializer;
use Ecotone\Tempest\Container\EcotoneInitializer;
use Ecotone\Tempest\Container\EventBusInitializer;
use Ecotone\Tempest\Container\QueryBusInitializer;
use Tempest\Container\Container;
use Tempest\Core\KernelEvent;
use Tempest\EventBus\EventHandler;

/**
 * licence Apache-2.0
 */
final class TempestEcotoneProvider
{
    public function __construct(
        private Container $container,
    ) {
    }

    #[EventHandler(KernelEvent::BOOTED)]
    public function initialize(): void
    {
        // The initializers will be automatically discovered by Tempest
        // and registered in the container due to the #[Singleton] attribute
        // This method just ensures the provider is loaded
    }
}
