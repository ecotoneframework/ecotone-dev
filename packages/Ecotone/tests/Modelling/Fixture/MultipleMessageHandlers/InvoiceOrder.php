<?php

namespace Test\Ecotone\Modelling\Fixture\MultipleMessageHandlers;

use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\Saga;

#[Saga]
class InvoiceOrder
{
    private array $products;

    public function __construct(
        #[Identifier] private string $debtorId,
    ) {
        $this->products = [];
    }

    public function products(): array
    {
        return $this->products;
    }

    #[EventHandler(endpointId: 'createWhenProductBecameBillable', identifierMapping: ['debtorId' => 'payload.debtor'])]
    public static function createWhenProductBecameBillable(
        #[Payload] ProductBecameBillable $event
    ): self {
        $invoiceOrder = new self($event->debtor);
        $invoiceOrder->products[] = $event->productId;

        return $invoiceOrder;
    }

    #[EventHandler(endpointId: 'createWhenDebtorWasChanged', identifierMapping: ['debtorId' => 'payload.newDebtor'])]
    public static function createWhenDebtorWasChanged(
        #[Payload] ProductDebtorWasChanged $event
    ): self {
        $invoiceOrder = new self($event->newDebtor);
        $invoiceOrder->products[] = $event->productId;

        return $invoiceOrder;
    }

    #[EventHandler(endpointId: 'removeProductWhenDebtorWasChanged', identifierMapping: ['debtorId' => 'payload.oldDebtor'])]
    public function removeProductWhenDebtorWasChanged(
        #[Payload] ProductDebtorWasChanged $event
    ): void {
        unset($this->products[$event->productId]);
    }

    #[EventHandler(endpointId: 'addProductWhenDebtorWasChanged', identifierMapping: ['debtorId' => 'payload.newDebtor'])]
    public function addProductWhenDebtorWasChanged(
        #[Payload] ProductDebtorWasChanged $event
    ): void {
        if (!in_array($event->productId, $this->products, true)) {
            $this->products[] = $event->productId;
        }
    }
}
