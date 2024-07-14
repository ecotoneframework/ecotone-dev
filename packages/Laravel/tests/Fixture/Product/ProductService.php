<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\Product;

use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class ProductService
{
    #[CommandHandler]
    public function registerProduct(RegisterProduct $registerProduct): void
    {
        $product = Product::create([
            'id' => $registerProduct->id,
            'name' => $registerProduct->name,
            'price_amount' => $registerProduct->price->getAmount(),
            'price_currency' => $registerProduct->price->getCurrency()->getCode(),
        ]);

        $product->save();
    }
}
