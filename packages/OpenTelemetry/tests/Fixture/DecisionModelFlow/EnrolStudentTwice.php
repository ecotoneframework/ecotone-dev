<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow;

/**
 * licence Apache-2.0
 */
final readonly class EnrolStudentTwice
{
    public function __construct(
        public string $courseId,
        public string $studentId,
    ) {
    }
}
