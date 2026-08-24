<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\DifferentStreamSameType;

use Ecotone\Api\AggregateType;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
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
