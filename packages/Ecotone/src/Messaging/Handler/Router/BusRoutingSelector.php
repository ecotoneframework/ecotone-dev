<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Router;

use Ecotone\Messaging\Message;

class BusRoutingSelector implements RouteSelector
{
    public const ROUTING_KEY_HEADER = 'ecotone.routing.routingKey';

    public function __construct(private BusRoutingConfig $busRoutingConfig)
    {
    }

    /**
     * @inheritDoc
     */
    public function route(Message $message): array
    {
        if ($message->getHeaders()->containsKey(self::ROUTING_KEY_HEADER)) {
            $routingKey = $message->getHeaders()->get(self::ROUTING_KEY_HEADER);
            if (! \is_string($routingKey)) {
                throw new \InvalidArgumentException(sprintf('Routing key should be a string, but got %s', \gettype($routingKey)));
            }
        } else {
            $payload = $message->getPayload();
            if (!\is_object($payload)) {
                throw new \InvalidArgumentException(sprintf('Routing key should be provided in the message header \''.self::ROUTING_KEY_HEADER . '\' or the payload should be an object, but got %s', \gettype($payload)));
            }
            $routingKey = get_class($payload);
        }

        return $this->busRoutingConfig->resolve($routingKey);
    }
}