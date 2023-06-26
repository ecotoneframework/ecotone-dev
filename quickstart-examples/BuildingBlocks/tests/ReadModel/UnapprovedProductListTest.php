<?php

declare(strict_types=1);

namespace ReadModel;

use App\Domain\Product\Event\ProductWasAdded;
use App\Domain\Product\Event\ProductWasApproved;
use App\Domain\Product\Product;
use App\Infrastructure\Converter\UuidConverter;
use App\ReadModel\UnapprovedProductList;
use Ecotone\Lite\EcotoneLite;
use Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class UnapprovedProductListTest extends TestCase
{
    public function test_list_unapproved_products_when_there_is_none()
    {
        $this->assertEquals(
            [],
            EcotoneLite::bootstrapFlowTestingWithEventStore([UnapprovedProductList::class, Product::class, UuidConverter::class], [new UnapprovedProductList(), new UuidConverter()])
                ->sendQueryWithRouting('getUnapprovedProducts')
        );
    }

    public function test_list_unapproved_products()
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
                ->withEventsFor($productId, Product::class, [
                    new ProductWasAdded(Uuid::fromString($productId), $productName, Money::EUR($productPrice))
                ])
                ->sendQueryWithRouting('getUnapprovedProducts')
        );
    }

    public function test_list_when_product_was_approved()
    {
        $productId = Uuid::uuid4()->toString();
        $productName = 'Wooden table';
        $productPrice = 1000;

        $this->assertEquals(
            [],
            EcotoneLite::bootstrapFlowTestingWithEventStore([UnapprovedProductList::class, Product::class, UuidConverter::class], [new UnapprovedProductList(), new UuidConverter()])
                ->withEventsFor($productId, Product::class, [
                    new ProductWasAdded(Uuid::fromString($productId), $productName, Money::EUR($productPrice)),
                    new ProductWasApproved(Uuid::fromString($productId))
                ])
                ->sendQueryWithRouting('getUnapprovedProducts')
        );
    }
}