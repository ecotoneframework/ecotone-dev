<?php

namespace Ecotone\Messaging\Handler\Router;

use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Support\InvalidArgumentException;

class InMemoryRouteResolver implements RouteResolver
{
    /**
     * @param array<string, RealMessageProcessor> $routeMap
     */
    public function __construct(
        private array $routeMap
    ) {
    }

    public function resolve(string $routeName): RealMessageProcessor
    {
        return $this->routeMap[$routeName] ?? throw InvalidArgumentException::create("No route found for name {$routeName}");
    }
}