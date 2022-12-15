<?php

declare(strict_types=1);

namespace Test\App\Unit\Domain\Product;

use App\Testing\Domain\Product\Command\AddProduct;
use App\Testing\Domain\Product\Event\ProductWasAdded;
use App\Testing\Domain\Product\Product;
use Ecotone\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class ProductTest extends TestCase
{
    public function test_registering_new_product()
    {
        $productId = Uuid::uuid4();
        $name = "Milk";
        $price = 100;

        /** Verifying published events by aggregate, after calling command */
        $this->assertEquals(
            [new ProductWasAdded($productId,$name,$price)],
            EcotoneLite::bootstrapFlowTesting([Product::class])
                ->sendCommand(new AddProduct($productId, $name, $price))
                ->getRecordedEvents()
        );
    }
}