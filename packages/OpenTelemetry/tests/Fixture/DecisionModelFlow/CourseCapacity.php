<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow;

use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\DecisionModel;

/**
 * licence Apache-2.0
 */
#[DecisionModel]
final class CourseCapacity
{
    private int $capacity = 0;

    private int $seatsTaken = 0;

    #[Apply]
    public function defined(CourseDefined $event): void
    {
        $this->capacity = $event->capacity;
    }

    #[Apply]
    public function enrolled(StudentEnrolled $event): void
    {
        $this->seatsTaken++;
    }

    public function hasFreeSeat(): bool
    {
        return $this->seatsTaken < $this->capacity;
    }
}
