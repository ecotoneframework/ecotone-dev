<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Container;

use Ecotone\Messaging\Attribute\BusinessMethod;
use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Tempest\Container\Container;
use Tempest\Container\DynamicInitializer;
use Tempest\Reflection\ClassReflector;
use function Tempest\Support\Str\starts_with;

/**
 * licence Apache-2.0
 */
final class GatewayInitializer implements DynamicInitializer
{
    public function canInitialize(ClassReflector $class, null|string|\UnitEnum $tag): bool
    {
        /** Must be interface to be Gateway */
        if (!interface_exists($class->getName())) {
            return false;
        }

        if (starts_with($class->getName(), 'Ecotone\\')) {
            return true;
        }

        foreach ($class->getPublicMethods() as $method) {
            if ($method->hasAttribute(MessageGateway::class) || $method->hasAttribute(BusinessMethod::class)) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function initialize(ClassReflector $class, null|string|\UnitEnum $tag, Container $container): object
    {
        /** @var ConfiguredMessagingSystem $messagingSystem */
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);

        return $messagingSystem->getGatewayByName($class->getName());
    }
}
