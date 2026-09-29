<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow;

use Ecotone\Api\Attribute\EventTag;

/**
 * licence Apache-2.0
 */
final readonly class StudentEnrolled
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public string $studentId,
    ) {
    }
}
