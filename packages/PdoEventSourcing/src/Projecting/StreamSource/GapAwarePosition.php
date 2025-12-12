<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\StreamSource;

use function array_key_last;

use Ecotone\Messaging\Support\Assert;

use function implode;
use function in_array;

use InvalidArgumentException;

use function sort;
use function sprintf;

class GapAwarePosition
{
    /**
     * @param list<int> $gaps
     */
    public function __construct(
        private int $position,
        private array $gaps = []
    ) {
        Assert::isTrue($this->position >= 0, 'Position must be a non-negative integer');
        $this->gaps = array_values(array_unique($gaps));
        sort($this->gaps);
        if (! empty($this->gaps)) {
            $maxGap = $this->gaps[array_key_last($this->gaps)];
            Assert::isTrue($maxGap <= $this->position, 'Max gap must be less than or equal to position');
        }
    }

    public static function fromString(?string $position): self
    {
        if (empty($position)) {
            return new self(0);
        }

        $parts = explode(':', $position);
        Assert::isTrue(count($parts) === 2, 'Invalid position format. Expected "position:gaps"');

        $position = (int) $parts[0];
        if (empty($parts[1])) {
            $gaps = [];
        } else {
            $gaps = array_map('intval', explode(',', $parts[1]));
        }

        return new self($position, $gaps);
    }

    public function __toString(): string
    {
        return sprintf('%d:%s', $this->position, implode(',', $this->gaps));
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * @return list<int>
     */
    public function getGaps(): array
    {
        return $this->gaps;
    }

    /**
     * Advance the position to the given position.
     * If $insertGaps is true (default), insert detected gaps between the current position and the new position for eventual filling.
     * $insertGaps will typically be set to false when backfilling events or when processing old events, as we know that all gaps
     * in the stream are real gaps and will never be filled.
     */
    public function advanceTo(int $position, bool $insertGaps = true): void
    {
        if ($position > $this->position) {
            if ($insertGaps) {
                // add all gaps between current position and new position
                for ($i = $this->position + 1; $i < $position; $i++) {
                    $this->gaps[] = $i;
                }
            }
            $this->position = $position;
        } elseif (in_array($position, $this->gaps, true)) {
            // if the position is already in gaps, remove it
            $this->gaps = array_values(array_diff($this->gaps, [$position]));
        } else {
            throw new InvalidArgumentException('Cannot advance to a position less than or equal to the current position. Current position: ' . $this->position . ', new position: ' . $position);
        }
    }

    public function cleanByMaxOffset(int $maxOffset): void
    {
        if ($maxOffset <= 0 || empty($this->gaps)) {
            return;
        }
        $cutoff = $this->position - $maxOffset;
        $this->cutoffGapsBelow($cutoff);
    }

    public function cutoffGapsBelow(int $cutoffPosition): void
    {
        if (empty($this->gaps)) {
            return;
        }
        // Find first gap > cutoff, then slice
        foreach ($this->gaps as $index => $gap) {
            if ($gap >= $cutoffPosition) {
                $this->gaps = array_slice($this->gaps, $index);
                return;
            }
        }

        // All gaps are <= cutoff, remove all
        $this->gaps = [];
    }
}
