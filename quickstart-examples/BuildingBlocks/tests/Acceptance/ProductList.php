<?php

declare(strict_types=1);

namespace Acceptance;

use App\Domain\Product\Command\CreateProduct;
use App\Domain\Product\Product;
use App\Infrastructure\Converter\UuidConverter;
use App\ReadModel\UnapprovedProductList;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * @internal
 */
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
                    'price' => $productPrice,
                ],
            ],
            EcotoneLite::bootstrapFlowTesting([UnapprovedProductList::class, Product::class, UuidConverter::class], [new UnapprovedProductList(), new UuidConverter()], configuration: ServiceConfiguration::createWithDefaults()->withEnabledModulePackageNames([ModulePackageList::EVENT_SOURCING_PACKAGE, ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE]))
                ->sendCommand(new CreateProduct(
                    Uuid::fromString($productId),
                    $productName,
                    Money::EUR($productPrice)
                ))
                ->sendQueryWithRouting('getUnapprovedProducts')
        );
    }
}
