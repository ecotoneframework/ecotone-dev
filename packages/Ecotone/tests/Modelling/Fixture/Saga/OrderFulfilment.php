<?php

namespace Test\Ecotone\Modelling\Fixture\Saga;

use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Saga;
use Ecotone\Modelling\Attribute\SagaIdentifier;

#[Saga]
class OrderFulfilment
{
    #[SagaIdentifier]
    private $orderId;
    /**
     * @var string
     */
    private $status;

    /**
     * Article constructor.
     *
     * @param string $orderId
     */
    private function __construct(string $orderId)
    {
        $this->orderId  = $orderId;
        $this->status = 'new';
    }

    #[EventHandler]
    public static function createWith(string $orderId): self
    {
        return new self($orderId);
    }

    #[EventHandler]
    public function finishOrder(PaymentWasDoneEvent $event): void
    {
        $this->status = 'done';
    }

    public function getId(): string
    {
        return $this->orderId;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }
}
