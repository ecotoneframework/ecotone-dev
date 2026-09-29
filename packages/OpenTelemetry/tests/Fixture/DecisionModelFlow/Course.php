<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow;

use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

/**
 * licence Apache-2.0
 */
#[EventSourcingAggregate]
#[AggregateType('Course')]
final class Course
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $courseId;

    #[CommandHandler]
    public static function define(DefineCourse $command, CourseCapacity $capacity): array
    {
        return [new CourseDefined($command->courseId, $command->capacity)];
    }

    #[EventSourcingHandler]
    public function applyDefined(CourseDefined $event): void
    {
        $this->courseId = $event->courseId;
    }
}
