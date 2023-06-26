<?php

declare(strict_types=1);

namespace Acceptance;

use App\Domain\Product\Command\CreateProduct;
use App\Domain\Product\Event\ProductWasAdded;
use App\Domain\Product\Product;
use App\Infrastructure\Converter\UuidConverter;
use App\ReadModel\UnapprovedProductList;
use Ecotone\Lite\EcotoneLite;
use Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class ProductList extends TestCase
{
    #[Test]
    public function creating_unapproved_products()
    {
        $productId = Uuid::uuid4()->toString();
        $productName = 'Wooden table';
        $productPrice = 1000;

        $this->assertEquals(
            [
                [
                    'productId' => $productId,
                    'name' => $productName,
                    'price' => $productPrice
                ]
            ],
            EcotoneLite::bootstrapFlowTestingWithEventStore([UnapprovedProductList::class, Product::class, UuidConverter::class], [new UnapprovedProductList(), new UuidConverter()])
                ->sendCommand(new CreateProduct(
                    Uuid::fromString($productId),
                    $productName,
                    Money::EUR($productPrice)
                ))
                ->sendQueryWithRouting('getUnapprovedProducts')
        );
    }
}