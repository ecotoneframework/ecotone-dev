<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure;

use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Monorepo\ExampleApp\Common\Domain\Product\ProductDetails;
use Monorepo\ExampleApp\Common\Domain\Shipping\ShippingService;
use Ramsey\Uuid\UuidInterface;

final class StubShippingService implements ShippingService
{
    public function __construct(private Output $output, private Configuration $configuration)
    {}

    public function shipOrderFor(UuidInterface $userId, UuidInterface $orderId, ProductDetails $productDetails, ShippingAddress $shippingAddress): void
    {
        /** In production run we would Shipping Service over HTTP  */

        if ($orderId->equals($this->configuration->failToNotifyOrder())) {
            throw new \InvalidArgumentException('Http failure while shipping product.');
        }

        $this->output->write(sprintf("Shipping products to %s %s!", $shippingAddress->street, $shippingAddress->houseNumber));
    }
}