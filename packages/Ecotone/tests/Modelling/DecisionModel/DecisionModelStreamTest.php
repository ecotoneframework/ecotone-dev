<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionModelStreamTest extends TestCase
{
    public function test_stream_on_the_handler_method_wins_over_stream_on_the_class(): void
    {
        if (! class_exists(Stream::class)) {
            $this->markTestSkipped('Ecotone\Api\EventSourcing\Stream is not autoloadable outside a monorepo context that includes ecotone/pdo-event-sourcing.');
        }

        $handler = new StreamHandlerForStreamTest();

        $ecotone = EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, CourseForStreamTest::class, CourseDefinedForStreamTest::class],
            containerOrAvailableServices: [$handler],
            configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommand(new DefineCourseForStreamTest('course-1', 10));

        $this->assertCount(0, $ecotone->getEventStreamEvents('ecotone_event_stream'));
        $this->assertCount(1, $ecotone->getEventStreamEvents('method_level_stream'));
    }
}

final readonly class DefineCourseForStreamTest
{
    public function __construct(
        public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class CourseDefinedForStreamTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}

#[DecisionModel]
final class CourseForStreamTest
{
    private int $capacity = 0;

    #[EventSourcingHandler]
    public function defined(CourseDefinedForStreamTest $event): void
    {
        $this->capacity = $event->capacity;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}

#[Stream('class_level_stream')]
final class StreamHandlerForStreamTest
{
    #[Stream('method_level_stream')]
    #[CommandHandler]
    public function define(DefineCourseForStreamTest $command, CourseForStreamTest $course): array
    {
        return [new CourseDefinedForStreamTest($command->courseId, $command->capacity)];
    }
}
