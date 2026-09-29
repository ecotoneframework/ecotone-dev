<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow;

use Ecotone\Api\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class EnrolmentsWithoutBoundary
{
    #[CommandHandler('enrolment.withoutBoundary')]
    public function enrol(string $courseId): void
    {
    }
}
