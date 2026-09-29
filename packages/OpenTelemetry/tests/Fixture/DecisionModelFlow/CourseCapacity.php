<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow;

use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;

/**
 * licence Apache-2.0
 */
#[DecisionModel]
final class CourseCapacity
{
    private int $capacity = 0;

    private int $seatsTaken = 0;

    #[EventSourcingHandler]
    public function defined(CourseDefined $event): void
    {
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler]
    public function enrolled(StudentEnrolled $event): void
    {
        $this->seatsTaken++;
    }

    public function hasFreeSeat(): bool
    {
        return $this->seatsTaken < $this->capacity;
    }
}
