<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Gateway\CommandBus;

/**
 * licence Apache-2.0
 */
final class Enrolments
{
    #[CommandHandler]
    public function enrol(EnrolStudent $command, CourseCapacity $course): array
    {
        return [new StudentEnrolled($command->courseId, $command->studentId)];
    }

    #[CommandHandler]
    public function enrolTwice(EnrolStudentTwice $command, CourseCapacity $course, CommandBus $commandBus): array
    {
        $commandBus->send(new EnrolStudent($command->courseId, $command->studentId));

        return [new StudentEnrolled($command->courseId, $command->studentId)];
    }
}
