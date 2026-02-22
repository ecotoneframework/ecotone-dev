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
final class SharedStreamCategory
{
    use WithEvents;
    use WithAggregateVersioning;

    public const STREAM = 'shared_stream';
    public const AGGREGATE_TYPE = 'category';

    #[Identifier]
    public string $categoryId;

    #[CommandHandler]
    public static function create(CreateCategory $command): self
    {
        $category = new self();
        $category->recordThat(new CategoryCreated($command->categoryId));
        return $category;
    }

    #[EventSourcingHandler]
    public function applyCategoryCreated(CategoryCreated $event): void
    {
        $this->categoryId = $event->categoryId;
    }
}
