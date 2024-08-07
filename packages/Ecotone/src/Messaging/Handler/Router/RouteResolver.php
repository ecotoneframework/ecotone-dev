<?php

namespace Ecotone\Messaging\Handler\Router;

use Ecotone\Messaging\Handler\RealMessageProcessor;

interface RouteResolver
{
    public function resolve(string $routeName): RealMessageProcessor;
}