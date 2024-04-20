<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure\InMemory;

use Ecotone\Modelling\Attribute\Repository;
use Monorepo\ExampleApp\Common\Domain\Product\Product;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Ramsey\Uuid\UuidInterface;


final class InMemoryProductRepository implements ProductRepository
{
    /** @var Product[] */
    private array $products;

    /**
     * @param Product[] $products
     */
    public function __construct(array $products = [])
    {
        foreach ($products as $product) {
            $this->save( $product);
        }
    }

    public function getBy(UuidInterface $productId): Product
    {
        if (!isset($this->products[$productId->toString()])) {
            throw new \RuntimeException(sprintf("Product with id %s not found", $productId->toString()));
        }

        return $this->products[$productId->toString()];
    }

    public function save(Product $product): void
    {
        $this->products[$product->getProductId()->toString()] = $product;
    }
}