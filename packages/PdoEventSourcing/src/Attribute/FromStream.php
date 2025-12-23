<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Attribute;

use Attribute;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Messaging\Support\Assert;

#[Attribute(Attribute::TARGET_CLASS)]
class FromStream
{
    /** @var string[] */
    public readonly array $streams;
    public readonly ?string $aggregateType;
    public readonly string $eventStoreReferenceName;

    /**
     * Accepts a single stream name or a list of stream names.
     */
    public function __construct(string|array $stream, ?string $aggregateType = null, string $eventStoreReferenceName = EventStore::class)
    {
        // Keep original parameter name `$stream` for backward compatibility with existing tests/usages
        $streams = is_array($stream) ? $stream : [$stream];
        Assert::isTrue(!empty($streams), 'At least one stream name must be provided');
        foreach ($streams as $s) {
            Assert::notNullAndEmpty($s, "Stream name can't be empty");
        }

        $this->streams = array_values($streams);
        $this->aggregateType = $aggregateType;
        $this->eventStoreReferenceName = $eventStoreReferenceName;
    }

    /**
     * Backward compatibility accessor for single-stream cases.
     */
    public function getStream(): string
    {
        return $this->streams[0];
    }

    /**
     * @return string[]
     */
    public function getStreams(): array
    {
        return $this->streams;
    }

    public function isMultiStream(): bool
    {
        return count($this->streams) > 1;
    }
}
