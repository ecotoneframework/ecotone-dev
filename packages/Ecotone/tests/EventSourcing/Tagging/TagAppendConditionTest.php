<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Tagging;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class TagAppendConditionTest extends TestCase
{
    public function test_a_condition_guards_its_tags_even_when_the_appended_events_carry_another_value(): void
    {
        $eventStore = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [CourseChangedForAppendConditionTest::class],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->getGateway(EventStore::class);
        $eventStore->appendTo('ecotone_event_stream', [new CourseChangedForAppendConditionTest('course-1')]);
        $loaded = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));
        $eventStore->appendTo('ecotone_event_stream', [new CourseChangedForAppendConditionTest('course-1')]);

        $this->expectException(DecisionModelConcurrencyException::class);

        $eventStore->appendTo('ecotone_event_stream', [new CourseChangedForAppendConditionTest('course-2')], $loaded->appendCondition);
    }
}

final readonly class CourseChangedForAppendConditionTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
    ) {
    }
}
