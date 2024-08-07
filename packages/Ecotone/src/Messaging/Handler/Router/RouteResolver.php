<?php

namespace Ecotone\Messaging\Handler\Router;

use Ecotone\Messaging\Handler\MessageProcessor;

interface RouteResolver
{
    public function resolve(string $routeName): MessageProcessor;
}
