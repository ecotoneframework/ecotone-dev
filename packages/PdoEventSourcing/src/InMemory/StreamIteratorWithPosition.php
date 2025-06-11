<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\InMemory;

use Iterator;
use Prooph\EventStore\StreamIterator\StreamIterator;

class StreamIteratorWithPosition implements StreamIterator
{
    private int $currentPosition;
    public function __construct(private Iterator $decorated, private int $initialPosition)
    {
        $this->currentPosition = $this->initialPosition - 1;
    }

    public function current(): mixed
    {
        return $this->decorated->current()->withAddedMetadata('_position', $this->currentPosition);
    }

    public function next(): void
    {
        $this->decorated->next();
        $this->currentPosition++;
    }

    public function key(): mixed
    {
        return $this->decorated->key();
    }

    public function valid(): bool
    {
        return $this->decorated->valid();
    }

    public function rewind(): void
    {
        $this->decorated->rewind();
        $this->currentPosition = $this->initialPosition;
    }

    public function count(): int
    {
        return $this->decorated->count();
    }
}