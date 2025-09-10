<?php

namespace Ecotone\EventSourcing\Attribute;

use Attribute;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Attribute\StreamBasedSource;
use Ecotone\Messaging\Support\Assert;

#[Attribute(Attribute::TARGET_CLASS)]
/**
 * licence Apache-2.0
 */
class Projection extends StreamBasedSource
{
    private string $name;
    private array $fromStreams;
    private array|string $fromCategories;
    private bool $fromAll;
    private string $eventStoreReferenceName;

    public function __construct(string $name, string|array $fromStreams = [], string|array $fromCategories = [], bool $fromAll = false, string $eventStoreReferenceName = EventStore::class)
    {
        $fromStreams = is_string($fromStreams) ? [$fromStreams] : $fromStreams;
        $fromCategories = is_string($fromCategories) ? [$fromCategories] : $fromCategories;
        $countDefined = (int)$fromStreams + (int)$fromCategories + (int)$fromAll;
        Assert::isTrue($countDefined === 1, 'Projection should be defined only with one of `fromStreams`, `fromCategories` or `fromALl`');

        $this->name = $name;
        $this->fromStreams = $fromStreams;
        $this->fromCategories = $fromCategories;
        $this->fromAll = $fromAll;
        $this->eventStoreReferenceName = $eventStoreReferenceName;
    }

    public function getEventStoreReferenceName(): string
    {
        return $this->eventStoreReferenceName;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFromStreams(): array
    {
        return $this->fromStreams;
    }

    public function getFromCategories(): array|string
    {
        return $this->fromCategories;
    }

    public function isFromAll(): bool
    {
        return $this->fromAll;
    }
}
