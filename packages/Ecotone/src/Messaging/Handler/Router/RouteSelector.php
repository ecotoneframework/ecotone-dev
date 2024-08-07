<?php

namespace Ecotone\Messaging\Handler\Router;

use Ecotone\Messaging\Message;

interface RouteSelector
{
    /**
     * @param Message $message
     * @return string[]
     */
    public function route(Message $message): array;
}