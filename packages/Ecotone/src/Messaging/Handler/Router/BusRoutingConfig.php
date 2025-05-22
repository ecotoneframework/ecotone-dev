<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Router;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Message;

class BusRoutingConfig implements CompilableBuilder
{
    public function __construct(
        /**
         * @var array<string, int> key is the channel name, value is the priority
         */
        private array $channelsPriority = [],
        /**
         * @var array<string, list<string>> key is the class name, value is the list of channels
         */
        private array $objectRoutes = [],

        /**
         * @var list<string> list of channels
         */
        private array $catchAllRoutes = [],
        /**
         * @var array<string, list<string>> key is the route name, value is the list of channels
         */
        private array $namedRoutes = [],
        /**
         * @var array<string, list<string>> key is the regex pattern, value is the list of channels
         */
        private array $regexRoutes = [],
        /**
         * @var array<string, string> key is the class name, value is the routing key
         */
        private array $classToNameAliases = [],
        /**
         * @var array<string, string> key is the routing key, value is the class name
         */
        private array $nameToClassAliases = [],

        /**
         * @var array<string, list<string>> key is the routing key, value is the full list of channels
         */
        private array $optimizedRoutes = [],
    )
    {
    }

    /**
     * @param class-string $class
     */
    public function addObjectRoute(string $class, string $channel, int $priority = 1): void
    {
        $this->assertIsNotOptimized();
        $this->addChannel($channel, $priority);
        $this->objectRoutes[$class][] = $channel;
    }

    private function assertIsNotOptimized(string $message = 'RoutingConfig is already optimized. Cannot add more routes.'): void
    {
        if ($this->isOptimized()) {
            throw new \RuntimeException($message);
        }
    }

    public function isOptimized(): bool
    {
        return !empty($this->optimizedRoutes);
    }

    private function addChannel(string $channel, int $priority): void
    {
        if (isset($this->channelsPriority[$channel]) && $this->channelsPriority[$channel] !== $priority) {
            throw new \RuntimeException("Channel $channel is already registered with another priority");
        }
        $this->channelsPriority[$channel] = $priority;
    }

    public function addCatchAllRoute(string $channel, int $priority = 1): void
    {
        $this->assertIsNotOptimized();
        $this->addChannel($channel, $priority);
        $this->catchAllRoutes[] = $channel;
    }

    public function addNamedRoute(string $routeName, string $channel, int $priority = 1): void
    {
        $this->assertIsNotOptimized();
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
        $this->assertIsNotOptimized();
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

    private function resolveWithoutOptimization(string $routingKeyOrClass): array
    {
        $result = [];
        $isObject = \class_exists($routingKeyOrClass);

        if ($isObject) {
            $className = $routingKeyOrClass;
            $routingKey = $this->classToNameAliases[$routingKeyOrClass] ?? null;
        } else {
            $routingKey = $routingKeyOrClass;
            $className = $this->nameToClassAliases[$routingKeyOrClass] ?? null;
        }

        if ($className) {
            $classRoutingKeys = $this->getClassInheritanceRoutes($className);
            foreach ($classRoutingKeys as $classRoutingKey) {
                $result = array_merge($result, $this->objectRoutes[$classRoutingKey]);
            }

            $result = array_merge($result, $this->catchAllRoutes);
        }

        if ($routingKey) {
            if (isset($this->namedRoutes[$routingKey])) {
                $result = array_merge($result, $this->namedRoutes[$routingKey]);
            }
            foreach ($this->regexRoutes as $pattern => $routes) {
                if (preg_match($pattern, $routingKey)) {
                    $result = array_merge($result, $routes);
                }
            }
        }

        $result = \array_unique($result);

        \usort($result, $this->sortByChannelPriority(...));

        return $result;
    }

    /**
     * @param class-string $classString
     */
    private function getClassInheritanceRoutes(string $classString): array
    {
        $resultRoutingKeys = [];
        foreach ($this->objectRoutes as $routingKey => $routes) {
            if (is_a($classString, $routingKey, true)) {
                $resultRoutingKeys[] = $routingKey;
            }
        }
        return $resultRoutingKeys;
    }

    public function resolve(string $routingKeyOrClass): array
    {
        if (isset($this->optimizedRoutes[$routingKeyOrClass])) {
            return $this->optimizedRoutes[$routingKeyOrClass];
        }

        return $this->resolveWithoutOptimization($routingKeyOrClass);
    }

    private function sortByChannelPriority(string $a, string $b): int
    {
        return $this->channelsPriority[$b] <=> $this->channelsPriority[$a];
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        $this->optimize();
        return new Definition(self::class, [
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