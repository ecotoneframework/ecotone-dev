<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Router;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;

class BusRoutingConfigBuilder extends BusRoutingConfig
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param class-string $class
     * @param int|int[] $priority
     */
    public function addObjectRoute(string $class, string $channel, int|array $priority = 1): void
    {
        if ($class === 'object') {
            $this->addCatchAllRoute($channel, $priority);
        } else {
            $this->addChannel($channel, $priority);
            $this->objectRoutes[$class][] = $channel;
        }
    }


    /**
     * @param int|int[] $priority
     */
    public function addCatchAllRoute(string $channel, int|array $priority = 1): void
    {
        $this->addChannel($channel, $priority);
        $this->catchAllRoutes[] = $channel;
    }

    public function merge(self $routingConfig): void
    {
        foreach ($routingConfig->channelsPriority as $channel => $priority) {
            $this->addChannel($channel, $priority);
        }
        foreach ($routingConfig->objectRoutes as $class => $channels) {
            foreach ($channels as $channel) {
                $this->objectRoutes[$class][] = $channel;
            }
        }
        foreach ($routingConfig->catchAllRoutes as $channel) {
            $this->catchAllRoutes[] = $channel;
        }
        foreach ($routingConfig->namedRoutes as $routeName => $channels) {
            foreach ($channels as $channel) {
                $this->namedRoutes[$routeName][] = $channel;
            }
        }
        foreach ($routingConfig->regexRoutes as $pattern => $channels) {
            foreach ($channels as $channel) {
                $this->regexRoutes[$pattern][] = $channel;
            }
        }
    }

    /**
     * @param int|int[] $priority
     */
    public function addNamedRoute(string $routeName, string $channel, int|array $priority = 1): void
    {
        $this->addChannel($channel, $priority);
        if (\str_contains($routeName, '*')) {
            $pattern = str_replace('\\', '\\\\', $routeName);
            $pattern = str_replace('.', '\\.', $pattern);
            $pattern = str_replace('*', '.*', $pattern);
            $pattern = '#^' . $pattern . '$#';
            $this->regexRoutes[$pattern][] = $channel;
        } else {
            $this->namedRoutes[$routeName][] = $channel;
        }
    }

    public function addObjectAlias(string $class, string $routingKey): void
    {
        $this->classToNameAliases[$class] = $routingKey;
        $this->nameToClassAliases[$routingKey] = $class;
    }

    public function optimize(array $routingKeysToOptimize = []): void
    {
        $allKnownRoutingKeys = array_merge(
            $routingKeysToOptimize,
            array_keys($this->objectRoutes),
            array_keys($this->namedRoutes),
            array_keys($this->classToNameAliases),
            array_keys($this->nameToClassAliases),
        );
        $allKnownRoutingKeys = \array_unique($allKnownRoutingKeys);
        foreach ($allKnownRoutingKeys as $routingKey) {
            $this->optimizedRoutes[$routingKey] = $this->resolveWithoutOptimization($routingKey);
        }
    }

    private function addChannel(string $channel, int|array $priority): void
    {
        if (!empty($this->optimizedRoutes)) {
            throw new \RuntimeException("Cannot add channel $channel to routing config, because it is already optimized");
        }

        if (isset($this->channelsPriority[$channel]) && $this->channelsPriority[$channel] !== $priority) {
            throw new \RuntimeException("Channel $channel is already registered with another priority");
        }
        $this->channelsPriority[$channel] = $priority;
    }

    public function compile(): Definition
    {
        $this->optimize();
        return new Definition(BusRoutingConfig::class, [
            $this->channelsPriority,
            $this->objectRoutes,
            $this->catchAllRoutes,
            $this->namedRoutes,
            $this->regexRoutes,
            $this->classToNameAliases,
            $this->nameToClassAliases,
            $this->optimizedRoutes,
        ]);
    }
}