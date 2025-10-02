<?php declare(strict_types=1);

namespace Monorepo\ExampleAppEventSourcing\Common\Event;

use Ecotone\Messaging\Attribute\Converter;

class ProductWasRegistered
{
    public function __construct(private string $productId, private float $price) {}

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    #[Converter]
    public static function fromArray(array $data): self
    {
        return new self($data['productId'], $data['price']);
    }

    #[Converter]
    public static function toArray(self $object): array
    {
        return [
            'productId' => $object->productId,
            'price' => $object->price,
        ];
    }
}