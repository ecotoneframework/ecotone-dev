<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Product;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Tempest\Container\Singleton;

/**
 * licence Apache-2.0
 */
#[Singleton]
final class ProductService
{
    private static array $products = [];

    #[CommandHandler]
    public function handle(RegisterProduct $command): void
    {
        $product = Product::register($command->productId, $command->name, $command->price);
        self::$products[$command->productId] = $product;
    }

    #[QueryHandler]
    public function getProduct(GetProduct $query): ?Product
    {
        return self::$products[$query->productId] ?? null;
    }

    public static function reset(): void
    {
        self::$products = [];
    }

    public static function getProducts(): array
    {
        return self::$products;
    }
}
