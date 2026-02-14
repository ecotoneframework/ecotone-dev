<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\SharedStream;

use Ecotone\EventSourcing\Attribute\AggregateType;
use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
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
