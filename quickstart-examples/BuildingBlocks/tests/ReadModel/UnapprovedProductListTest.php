<?php

declare(strict_types=1);

namespace ReadModel;

use App\Domain\Product\Event\ProductWasAdded;
use App\Domain\Product\Event\ProductWasApproved;
use App\Domain\Product\Product;
use App\Infrastructure\Converter\UuidConverter;
use App\ReadModel\UnapprovedProductList;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * @internal
 */
final class UnapprovedProductListTest extends TestCase
{
    public function test_list_unapproved_products_when_there_is_none()
    {
        $this->assertEquals(
            [],
            EcotoneLite::bootstrapFlowTesting([UnapprovedProductList::class, Product::class, UuidConverter::class], [new UnapprovedProductList(), new UuidConverter()], configuration: ServiceConfiguration::createWithDefaults()->withEnabledModulePackageNames([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->sendQueryWithRouting('getUnapprovedProducts')
        );
    }

    public function test_list_unapproved_products()
    {
        $productId = Uuid::uuid4()->toString();
        $productName = 'Wooden table';
        $productPrice = 1000;

        $productWasAdded = new ProductWasAdded(Uuid::fromString($productId), $productName, Money::EUR($productPrice));

        $this->assertEquals(
            [
                [
                    'productId' => $productId,
                    'name' => $productName,
                    'price' => $productPrice,
                ],
            ],
            EcotoneLite::bootstrapFlowTesting([UnapprovedProductList::class, Product::class, UuidConverter::class], [new UnapprovedProductList(), new UuidConverter()], configuration: ServiceConfiguration::createWithDefaults()->withEnabledModulePackageNames([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withEventsFor($productId, Product::class, [$productWasAdded])
                ->publishEvent($productWasAdded)
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
            EcotoneLite::bootstrapFlowTesting([UnapprovedProductList::class, Product::class, UuidConverter::class], [new UnapprovedProductList(), new UuidConverter()], configuration: ServiceConfiguration::createWithDefaults()->withEnabledModulePackageNames([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->withEventsFor($productId, Product::class, [
                    new ProductWasAdded(Uuid::fromString($productId), $productName, Money::EUR($productPrice)),
                    new ProductWasApproved(Uuid::fromString($productId)),
                ])
                ->sendQueryWithRouting('getUnapprovedProducts')
        );
    }
}
