<?php

namespace Test\Ecotone\Modelling\Fixture\InterceptedQueryAggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;
use Ecotone\Api\QueryHandler;
use Ecotone\Modelling\WithEvents;
use Test\Ecotone\Modelling\Fixture\InterceptedQueryAggregate\AddFranchiseMargin\AddFranchise;
use Test\Ecotone\Modelling\Fixture\InterceptedQueryAggregate\ProductToPriceExchange\ExchangeProductForPrice;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class ShopCalculator
{
    use WithEvents;

    #[Identifier]
    private string $shopId;

    private int $margin;

    private function __construct(string $shopId, int $margin)
    {
        $this->shopId = $shopId;
        $this->margin = $margin;
    }

    #[CommandHandler('shop.register')]
    public static function register(array $registerShop): self
    {
        return new self($registerShop['shopId'], $registerShop['margin']);
    }

    #[QueryHandler('shop.calculatePrice', outputChannelName: 'addVat')]
    #[AddFranchise]
    #[ExchangeProductForPrice]
    public function calculatePriceFor(array $query): int
    {
        return $query['productPrice'] + $this->margin;
    }
}
