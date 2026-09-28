<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Tagging;

use Ecotone\Api\EventSourcing\EventCriteria;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 */
final class EventCriteriaTest extends TestCase
{
    public function test_a_single_criterion_is_its_own_only_branch(): void
    {
        $criteria = EventCriteria::tag('course', 'course-1');

        self::assertSame([$criteria], $criteria->branches());
    }

    public function test_or_combines_two_criteria_into_two_branches(): void
    {
        $first = EventCriteria::tag('course', 'course-1');
        $second = EventCriteria::tag('course', 'course-2');

        $combined = $first->or($second);

        self::assertSame([$first, $second], $combined->branches());
    }

    public function test_or_chained_three_times_flattens_into_three_branches(): void
    {
        $first = EventCriteria::tag('course', 'course-1');
        $second = EventCriteria::tag('course', 'course-2');
        $third = EventCriteria::tag('course', 'course-3');

        $combined = $first->or($second)->or($third);

        self::assertSame([$first, $second, $third], $combined->branches());
    }

    public function test_and_tag_and_of_types_still_build_a_single_branch(): void
    {
        $criteria = EventCriteria::tag('course', 'course-1')
            ->andTag('student', 'student-1')
            ->ofTypes(self::class);

        self::assertSame([$criteria], $criteria->branches());
        self::assertSame(
            [['name' => 'course', 'value' => 'course-1'], ['name' => 'student', 'value' => 'student-1']],
            $criteria->tags(),
        );
        self::assertSame([self::class], $criteria->eventTypes());
    }

    public function test_narrowing_an_or_combination_by_another_tag_is_rejected_instead_of_dropping_its_branches(): void
    {
        $combined = EventCriteria::tag('course', 'course-1')->or(EventCriteria::tag('course', 'course-2'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('andTag');

        $combined->andTag('student', 'student-1');
    }

    public function test_narrowing_an_or_combination_by_event_types_is_rejected_instead_of_dropping_its_branches(): void
    {
        $combined = EventCriteria::tag('course', 'course-1')->or(EventCriteria::tag('course', 'course-2'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ofTypes');

        $combined->ofTypes(self::class);
    }
}
