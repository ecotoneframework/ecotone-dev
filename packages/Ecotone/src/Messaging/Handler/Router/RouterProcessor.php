<?php

namespace Ecotone\Messaging\Handler\Router;

use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
class RouterProcessor implements MessageProcessor
{
    public function __construct(
        private RouteSelector $routeSelector,
        private RouteResolver $routeResolver,
        private bool $singleRoute = true,
        private ?string $typeHeaderName = null
    ) {
    }

    public function process(Message $message): ?Message
    {
        $message = $this->addPayloadTypeId($message);

        $routes = $this->routeSelector->route($message);

        if ($this->singleRoute) {
            if (count($routes) === 0) {
                return null;
            } elseif (count($routes) > 1) {
                throw new InvalidArgumentException('Expected only one route to be selected, but got more');
            }
            $path = $this->routeResolver->resolve($routes[0]);
            return $path->process($message);
        } else {
            foreach ($routes as $route) {
                $path = $this->routeResolver->resolve($route);
                $path->process($message);
            }
            return null;
        }

    }

    private function addPayloadTypeId(Message $message): Message
    {
        if ($message->getHeaders()->containsKey(MessageHeaders::TYPE_ID)) {
            return $message;
        }

        if ($this->typeHeaderName && $message->getHeaders()->containsKey($this->typeHeaderName)) {
            $message = MessageBuilder::fromMessage($message)
                ->setHeader(MessageHeaders::TYPE_ID, $message->getHeaders()->get($this->typeHeaderName))
                ->build();
        } else if(\is_object($message->getPayload())) {
            $message = MessageBuilder::fromMessage($message)
                ->setHeader(MessageHeaders::TYPE_ID, get_class($message->getPayload()))
                ->build();
        }

        return $message;
    }
}
