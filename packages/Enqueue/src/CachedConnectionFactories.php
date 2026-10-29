<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;

/**
 * licence Apache-2.0
 */
final class CachedConnectionFactories
{
    /** @var array<string, CachedConnectionFactory> */
    private array $connectionFactories = [];

    public static function registerIn(Configuration|MessagingContainerBuilder $container): Reference
    {
        if ($container instanceof Configuration) {
            $container->registerServiceDefinition(self::class, new Definition(self::class));
        } elseif (! $container->has(self::class)) {
            $container->register(self::class, new Definition(self::class));
        }

        return Reference::to(self::class);
    }

    public static function definitionFor(Definition $reconnectableConnectionFactory, Configuration|MessagingContainerBuilder $container): Definition
    {
        return new Definition(CachedConnectionFactory::class, [$reconnectableConnectionFactory], [(string) self::registerIn($container), 'cachedFor']);
    }

    public function cachedFor(ReconnectableConnectionFactory $reconnectableConnectionFactory): CachedConnectionFactory
    {
        return $this->connectionFactories[$reconnectableConnectionFactory->getConnectionInstanceId()]
            ??= new CachedConnectionFactory($reconnectableConnectionFactory);
    }
}
