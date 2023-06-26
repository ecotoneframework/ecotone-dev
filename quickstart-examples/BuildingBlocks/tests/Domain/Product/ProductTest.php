<?php

declare(strict_types=1);

namespace Domain\Product;

use App\Domain\Product\Command\ApproveProduct;
use App\Domain\Product\Command\ChangeProductPrice;
use App\Domain\Product\Command\CreateProduct;
use App\Domain\Product\Event\ProductPriceWasChanged;
use App\Domain\Product\Event\ProductWasAdded;
use App\Domain\Product\Event\ProductWasApproved;
use App\Domain\Product\Product;
use App\Infrastructure\Converter\UuidConverter;
use Ecotone\Lite\EcotoneLite;
use Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class ProductTest extends TestCase
{
    public function test_adding_new_product()
    {
        $productId = Uuid::uuid4();

        $this->assertEquals(
            [new ProductWasAdded($productId, 'Wooden table', Money::EUR(1000))],
            EcotoneLite::bootstrapFlowTesting([Product::class])
                ->sendCommand(new CreateProduct(
                    $productId,
                    'Wooden table',
                    Money::EUR(1000)
                ))
                // get events that have been recorded along the way
                ->getRecordedEvents()
        );
    }

    public function test_changing_product_price()
    {
        $productId = Uuid::uuid4();

        $this->assertEquals(
            [new ProductPriceWasChanged($productId,  Money::EUR(2000))],
            EcotoneLite::bootstrapFlowTesting([Product::class])
                ->sendCommand(new CreateProduct(
                    $productId,
                    'Wooden table',
                    Money::EUR(1000)
                ))
                // discard previously recorded events
                ->discardRecordedMessages()
                ->sendCommand(new ChangeProductPrice(
                    $productId,
                    Money::EUR(2000)
                ))
                ->getRecordedEvents()
        );
    }

    public function test_approving_product()
    {
        $productId = Uuid::uuid4();

        $this->assertEquals(
            [new ProductWasApproved($productId)],
            EcotoneLite::bootstrapFlowTestingWithEventStore([Product::class, UuidConverter::class], [new UuidConverter()])
                // Set initial state
                ->withEventsFor($productId, Product::class, [
                    new ProductWasAdded($productId, 'Wooden table', Money::EUR(1000))
                ])
                // send product.approve command using routing key
                ->sendCommandWithRoutingKey("product.approve", metadata: ["aggregate.id" => $productId])
                ->getRecordedEvents()
        );
    }
}