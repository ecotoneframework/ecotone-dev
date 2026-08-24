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
