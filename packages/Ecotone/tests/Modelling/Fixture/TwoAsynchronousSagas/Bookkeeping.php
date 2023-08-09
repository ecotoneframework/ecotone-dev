<?php

namespace Test\Ecotone\Modelling\Fixture\TwoAsynchronousSagas;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\Attribute\Saga;
use InvalidArgumentException;

#[Asynchronous(MessagingConfiguration::ASYNCHRONOUS_CHANNEL)]
#[Saga]
class Bookkeeping
{
    public const GET_BOOKING_STATUS = 'getBookingStatus';
    #[Identifier]
    private string $orderId;
    private string $status;

    private function __construct(string $orderId)
    {
        $this->orderId  = $orderId;
        $this->status = 'awaitingPayment';
    }

    #[EventHandler(endpointId: 'Bookkeeping::createWith')]
    public static function createWith(OrderWasPlaced $event): self
    {
        return new self($event->getOrderId());
    }

    #[EventHandler(endpointId: 'Bookkeeping::when')]
    public function when(OrderWasPaid $event): void
    {
        if ($this->status === 'paid') {
            throw new InvalidArgumentException('Trying to pay second time');
        }

        $this->status = 'paid';
    }

    #[QueryHandler(self::GET_BOOKING_STATUS)]
    public function getStatus(): string
    {
        return $this->status;
    }

    public function getId(): string
    {
        return $this->orderId;
    }
}
