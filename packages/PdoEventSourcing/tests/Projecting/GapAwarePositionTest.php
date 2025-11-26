<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\EventSourcing\Projecting\StreamSource\GapAwarePosition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
class GapAwarePositionTest extends TestCase
{
    public function test_from_string_parses_empty_and_with_gaps(): void
    {
        $p1 = GapAwarePosition::fromString(null);
        $this->assertSame(0, $p1->getPosition());
        $this->assertSame([], $p1->getGaps());

        $p2 = GapAwarePosition::fromString('10:');
        $this->assertSame(10, $p2->getPosition());
        $this->assertSame([], $p2->getGaps());

        $p3 = GapAwarePosition::fromString('7:3,5,6');
        $this->assertSame(7, $p3->getPosition());
        $this->assertSame([3, 5, 6], $p3->getGaps());
    }

    public function test_to_string_serializes_position_and_sorted_unique_gaps(): void
    {
        $gapAware = new GapAwarePosition(7, [6, 3, 5, 3]);
        $this->assertSame('7:3,5,6', (string) $gapAware);
    }

    public function test_advance_sequential_increments_position(): void
    {
        $gapAware = new GapAwarePosition(0);
        $gapAware->advanceTo(1);
        $this->assertSame(1, $gapAware->getPosition());
        $this->assertSame([], $gapAware->getGaps());
    }

    public function test_advance_skipping_creates_gaps_and_sets_position(): void
    {
        $gapAware = new GapAwarePosition(0);
        $gapAware->advanceTo(4);
        $this->assertSame(4, $gapAware->getPosition());
        $this->assertSame([1, 2, 3], $gapAware->getGaps());
    }

    public function test_advance_to_existing_gap_removes_it(): void
    {
        $gapAware = new GapAwarePosition(0);
        $gapAware->advanceTo(5); // gaps: 1,2,3,4
        $gapAware->advanceTo(3); // fills gap 3
        $this->assertSame(5, $gapAware->getPosition());
        $this->assertSame([1, 2, 4], $gapAware->getGaps());
    }

    public function test_advance_to_lower_or_equal_position_throws(): void
    {
        $gapAware = new GapAwarePosition(5);
        $this->expectException(InvalidArgumentException::class);
        $gapAware->advanceTo(5);
    }

    public function test_clean_by_max_offset_removes_old_gaps_efficiently(): void
    {
        $gapAware = new GapAwarePosition(0);
        $gapAware->advanceTo(10); // gaps [1..9]
        // position = 10, threshold = 10 - 4 = 6 -> remove gaps < 6 => keep [6,7,8,9]
        $gapAware->cleanByMaxOffset(4);
        $this->assertSame([6, 7, 8, 9], $gapAware->getGaps());
        // Ensure idempotency
        $gapAware->cleanByMaxOffset(4);
        $this->assertSame([6, 7, 8, 9], $gapAware->getGaps());
    }

    public function test_clean_by_max_offset_noop_when_non_positive(): void
    {
        $gapAware = new GapAwarePosition(10, [2, 5, 9]);
        $gapAware->cleanByMaxOffset(0);
        $this->assertSame([2, 5, 9], $gapAware->getGaps());
        $gapAware->cleanByMaxOffset(-10);
        $this->assertSame([2, 5, 9], $gapAware->getGaps());
    }

    public function test_cutoff_gaps_below_removes_gaps_below_cutoff(): void
    {
        $gapAware = new GapAwarePosition(15, [2, 5, 7, 9, 12]);
        $gapAware->cutoffGapsBelow(6);
        $this->assertSame([7, 9, 12], $gapAware->getGaps());
    }

    public function test_cutoff_gaps_below_removes_all_when_cutoff_above_max_gap(): void
    {
        $gapAware = new GapAwarePosition(10, [2, 5, 7, 9]);
        $gapAware->cutoffGapsBelow(15);
        $this->assertSame([], $gapAware->getGaps());
    }

    public function test_cutoff_gaps_below_keeps_all_when_cutoff_below_min_gap(): void
    {
        $gapAware = new GapAwarePosition(15, [5, 7, 9, 12]);
        $gapAware->cutoffGapsBelow(3);
        $this->assertSame([5, 7, 9, 12], $gapAware->getGaps());
    }

    public function test_advance_to_without_inserting_gaps_can_process_existent_gap(): void
    {
        $gapAware = new GapAwarePosition(0);
        $gapAware->advanceTo(5); // gaps: 1,2,3,4
        $gapAware->advanceTo(3, false);
        $this->assertSame(5, $gapAware->getPosition());
        $this->assertSame([1, 2, 4], $gapAware->getGaps());
    }
}
