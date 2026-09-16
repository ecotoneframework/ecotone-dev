<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Modelling\WithEvents;

#[EventSourcingAggregate(true)]
#[Stream(self::STREAM)]
#[AggregateType(self::AGGREGATE_TYPE)]
final class DifferentStreamProductB
{
    use WithEvents;
    use WithAggregateVersioning;

    public const STREAM = 'product_stream_b';
    public const AGGREGATE_TYPE = 'product';

    #[Identifier]
    public string $productId;

    #[CommandHandler]
    public static function create(CreateProductB $command): self
    {
        $product = new self();
        $product->recordThat(new ProductBCreated($command->productId));
        return $product;
    }

    #[EventSourcingHandler]
    public function applyProductBCreated(ProductBCreated $event): void
    {
        $this->productId = $event->productId;
    }
}
