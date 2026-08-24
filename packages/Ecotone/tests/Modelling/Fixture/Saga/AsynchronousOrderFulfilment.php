<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\Saga;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventHandler;
use Ecotone\Api\Identifier;
use Ecotone\Api\QueryHandler;
use Ecotone\Api\Saga;

#[Saga]
/**
 * licence Apache-2.0
 */
final class AsynchronousOrderFulfilment
{
    #[Identifier]
    private string $orderId;
    private string $status;

    private function __construct(string $orderId)
    {
        $this->orderId  = $orderId;
        $this->status = 'new';
    }

    #[CommandHandler('order.start')]
    public static function createWith(string $orderId): self
    {
        return new self($orderId);
    }

    #[Asynchronous(channelName: 'async')]
    #[EventHandler(endpointId: 'order.finishOrder', identifierMetadataMapping: ['orderId' => 'paymentId'])]
    public function finishOrder(PaymentWasDoneEvent $event): void
    {
        $this->status = 'done';
    }

    public function getId(): string
    {
        return $this->orderId;
    }

    #[QueryHandler('order.status')]
    public function getStatus(): string
    {
        return $this->status;
    }
}
