<?php

declare(strict_types=1);

namespace Ecotone\Tempest\Container;

use Ecotone\Messaging\Attribute\BusinessMethod;
use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Ecotone\Modelling\QueryBus;
use Tempest\Container\Container;
use Tempest\Container\DynamicInitializer;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;
use Tempest\Reflection\ClassReflector;
use function Tempest\Support\Str\starts_with;

/**
 * licence Apache-2.0
 */
final class GatewayInitializer implements DynamicInitializer
{
    public function canInitialize(ClassReflector $class, ?string $tag): bool
    {
        /** Must be interface to be Gateway */
        if (!interface_exists($class->getName())) {
            return false;
        }

        foreach ($class->getPublicMethods() as $method) {
            if ($method->hasAttribute(MessageGateway::class) || $method->hasAttribute(BusinessMethod::class)) {
                return true;
            }
        }

        return starts_with($class->getName(), 'Ecotone\\');
    }

    #[\Override]
    public function initialize(ClassReflector $class, ?string $tag, Container $container): object
    {
        /** @var ConfiguredMessagingSystem $messagingSystem */
        $messagingSystem = $container->get(ConfiguredMessagingSystem::class);

        return $messagingSystem->getGatewayByName($class->getName());
    }
}
