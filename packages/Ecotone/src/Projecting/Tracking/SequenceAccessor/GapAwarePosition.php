<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Tracking\SequenceAccessor;

use Ecotone\Messaging\Support\Assert;

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
        \sort($this->gaps);
    }

    public static function fromString(?string $position): self
    {
        if ($position === null) {
            return new self(0);
        }

        $parts = explode(':', $position);
        Assert::isTrue(count($parts) === 2, 'Invalid position format. Expected "position:gaps"');

        $position = (int) $parts[0];
        if (empty($parts[1])) {
            $gaps= [];
        } else {
            $gaps = array_map('intval', explode(',', $parts[1]));
        }

        return new self($position, $gaps);
    }

    public function __toString(): string
    {
        return \sprintf('%d:%s', $this->position, \implode(',', $this->gaps));
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

    public function addGap(int $gap): void
    {
        if ($gap > $this->position) {
            throw new \InvalidArgumentException('Cannot add a gap greater than the current position. Current position: ' . $this->position . ', gap: ' . $gap);
        }
//        if ($gap === 0) {
//            // ignore position 0
//            return;
//        }
        if (!in_array($gap, $this->gaps, true)) {
            $this->gaps[] = $gap;
            sort($this->gaps);
        }
    }

    public function advanceTo(int $position): void
    {
        if ($position === $this->position + 1) {
            $this->position++;
        } else if (\in_array($position, $this->gaps, true)) {
            // if the position is already in gaps, remove it
            $this->gaps = array_values(array_diff($this->gaps, [$position]));
        } else if ($position > $this->position + 1) {
            // add all gaps between current position and new position
            for ($i = $this->position + 1; $i < $position; $i++) {
                $this->addGap($i);
            }
            $this->position = $position;
        } else {
            throw new \InvalidArgumentException('Cannot advance to a position less than or equal to the current position. Current position: ' . $this->position . ', new position: ' . $position);
        }
    }
}