<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\Projecting\SequenceAccessor;

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
        $gaps = array_map('intval', explode(',', $parts[1]));

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
        if (!in_array($gap, $this->gaps, true)) {
            $this->gaps[] = $gap;
            sort($this->gaps);
        }
    }

    public function advanceTo(int $position): void
    {
        Assert::isTrue($position > $this->position, 'Position must be greater than current position');

        if ($position === $this->position + 1) {
            $this->position++;
        } else {
            // add all gaps between current position and new position
            for ($i = $this->position + 1; $i < $position; $i++) {
                $this->addGap($i);
            }
            $this->position = $position;
        }
    }
}