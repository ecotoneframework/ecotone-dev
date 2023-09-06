<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Order;

use Ecotone\Modelling\Attribute\Identifier;
use Monorepo\ExampleApp\Common\Domain\Clock;
use Monorepo\ExampleApp\Common\Domain\Money;
use Monorepo\ExampleApp\Common\Domain\Order\Command\PlaceOrder;
use Monorepo\ExampleApp\Common\Domain\Order\Event\OrderWasPlaced;
use Monorepo\ExampleApp\Common\Domain\Product\ProductDetails;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\WithAggregateEvents;
use Ramsey\Uuid\UuidInterface;

#[Aggregate]
final class Order
{
    use WithAggregateEvents;

    private function __construct(#[Identifier] private UuidInterface $orderId, private UuidInterface $userId, private ShippingAddress $shippingAddress, private ProductDetails $productDetails, private \DateTimeImmutable $orderAt)
    {
        $this->recordThat(new OrderWasPlaced($this->orderId));
    }

    #[CommandHandler]
    public static function create(PlaceOrder $command, ProductRepository $productRepository, Clock $clock): self
    {
        $productDetails = $productRepository->getBy($command->productId)->getProductDetails();

        return new self($command->orderId, $command->userId, $command->shippingAddress, $productDetails, $clock->getCurrentTime());
    }

    public function getOrderId(): UuidInterface
    {
        return $this->orderId;
    }

    public function getUserId(): UuidInterface
    {
        return $this->userId;
    }

    public function getShippingAddress(): ShippingAddress
    {
        return $this->shippingAddress;
    }

    public function getProductDetails(): ProductDetails
    {
        return $this->productDetails;
    }

    public function getTotalPrice(): Money
    {
        return $this->productDetails->productPrice;
    }
}