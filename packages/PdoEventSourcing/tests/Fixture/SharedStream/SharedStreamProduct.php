<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\SharedStream;

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
final class SharedStreamProduct
{
    use WithEvents;
    use WithAggregateVersioning;

    public const STREAM = 'shared_stream';
    public const AGGREGATE_TYPE = 'product';

    #[Identifier]
    public string $productId;

    #[CommandHandler]
    public static function create(CreateProduct $command): self
    {
        $product = new self();
        $product->recordThat(new ProductCreated($command->productId));
        return $product;
    }

    #[EventSourcingHandler]
    public function applyProductCreated(ProductCreated $event): void
    {
        $this->productId = $event->productId;
    }
}
