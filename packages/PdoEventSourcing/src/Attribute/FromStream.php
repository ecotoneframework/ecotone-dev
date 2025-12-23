<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Attribute;

use Attribute;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Support\Assert;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class FromStream
{
    public readonly string $stream;
    public readonly ?string $aggregateType;
    public readonly string $eventStoreReferenceName;

    public function __construct(string $stream, ?string $aggregateType = null, string $eventStoreReferenceName = EventStore::class)
    {
        Assert::notNullAndEmpty($stream, "Stream name can't be empty");
        $this->stream = $stream;
        $this->aggregateType = $aggregateType;
        $this->eventStoreReferenceName = $eventStoreReferenceName;
    }

    public function getStream(): string
    {
        return $this->stream;
    }
}
