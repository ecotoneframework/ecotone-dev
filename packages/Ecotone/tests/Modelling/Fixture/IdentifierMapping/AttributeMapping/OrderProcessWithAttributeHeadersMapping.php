<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\IdentifierMapping\AttributeMapping;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\Saga;

#[Saga]
final class OrderProcessWithAttributeHeadersMapping
{
    #[Identifier]
    private string $orderId;
    private string $status;

    private function __construct(string $orderId)
    {
        $this->orderId = $orderId;
    }

    #[CommandHandler('startOrder')]
    public static function create(string $orderId): self
    {
        return new self($orderId);
    }

    #[EventHandler(identifierMapping: ['orderId' => "headers['orderId']"])]
    public function createWhen(OrderStarted $event): self
    {
        return new self($event->id);
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }
}