<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;

#[Singleton]
final class ConnectionFactoryInitializer implements Initializer
{
    public function initialize(Container $container): ConnectionFactory
    {
        return $container->get(ConfiguredMessagingSystem::class)
            ->getServiceFromContainer(DbalConnectionFactory::class);
    }
}
