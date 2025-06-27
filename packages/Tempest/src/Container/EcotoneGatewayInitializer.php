<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Container;

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Tempest\Container\Container;
use Tempest\Container\DynamicInitializer;

/**
 * Dynamic initializer that registers all Ecotone Gateways (Business Interfaces)
 * in the Tempest container automatically
 * 
 * licence Apache-2.0
 */
final class EcotoneGatewayInitializer implements DynamicInitializer
{
    public function canInitialize(string $className): bool
    {
        // Check if the class is an interface and if it has BusinessMethod attributes
        if (!interface_exists($className)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($className);
            
            // Check if any method has BusinessMethod attribute
            foreach ($reflection->getMethods() as $method) {
                $attributes = $method->getAttributes(\Ecotone\Modelling\Attribute\BusinessMethod::class);
                if (!empty($attributes)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    public function initialize(string $className, Container $container): object
    {
        /** @var ConfiguredMessagingSystem $messagingSystem */
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);
        
        // Get the gateway implementation from Ecotone's messaging system
        return $messagingSystem->getGatewayByName($className);
    }
}
