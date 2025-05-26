<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Modelling\Config\Routing;

use Ecotone\Messaging\Handler\Router\RouteSelector;
use Ecotone\Messaging\Message;

class BusRoutingSelector implements RouteSelector
{
    public function __construct(private BusRoutingConfig $busRoutingConfig, private string $routingKeyHeader)
    {
    }

    /**
     * @inheritDoc
     */
    public function route(Message $message): array
    {
        if ($message->getHeaders()->containsKey($this->routingKeyHeader)) {
            $routingKey = $message->getHeaders()->get($this->routingKeyHeader);
            if (! \is_string($routingKey)) {
                throw new \InvalidArgumentException(sprintf('Routing key should be a string, but got %s', \gettype($routingKey)));
            }
        } else {
            $payload = $message->getPayload();
            if (!\is_object($payload)) {
                throw new \InvalidArgumentException(sprintf('Routing key should be provided in the message header \''. $this->routingKeyHeader . '\' or the payload should be an object, but got %s', \gettype($payload)));
            }
            $routingKey = get_class($payload);
        }

        return $this->busRoutingConfig->resolve($routingKey);
    }
}