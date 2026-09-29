<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use function array_map;

use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\OpenTelemetry\DynamicConsistencyBoundarySpanAttributes;
use Ecotone\Test\LicenceTesting;

use function implode;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow\CourseCapacity;
use Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow\CourseDefined;
use Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow\Enrolments;
use Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow\EnrolmentsWithoutBoundary;
use Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow\EnrolStudent;
use Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow\EnrolStudentTwice;
use Test\Ecotone\OpenTelemetry\Fixture\DecisionModelFlow\StudentEnrolled;

/**
 * licence Apache-2.0
 * @internal
 */
final class DynamicConsistencyBoundaryTracingTest extends TracingTestCase
{
    public function test_the_pre_invocation_decision_load_is_a_span_naming_the_handler_and_what_it_read(): void
    {
        $exporter = new InMemoryExporter();
        $ecotone = $this->bootstrapWithDynamicConsistencyBoundary($exporter);
        $ecotone->withEvents([new CourseDefined('course-1', 5)]);

        $ecotone->sendCommand(new EnrolStudent('course-1', 'student-1'));

        $span = $this->spanNamed($exporter, 'Decision Models: ' . Enrolments::class . '::enrol');

        self::assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
        self::assertSame(CourseCapacity::class, $span->getAttributes()->get(DynamicConsistencyBoundarySpanAttributes::MODELS));
        self::assertSame('course:course-1', $span->getAttributes()->get(DynamicConsistencyBoundarySpanAttributes::TAGS));
        self::assertSame('course:course-1=1', $span->getAttributes()->get(DynamicConsistencyBoundarySpanAttributes::CAPTURED_VERSIONS));
        self::assertSame('', $span->getAttributes()->get(DynamicConsistencyBoundarySpanAttributes::AGGREGATES));
        self::assertSame(1, $span->getAttributes()->get(DynamicConsistencyBoundarySpanAttributes::EVENTS_FOLDED));
    }

    public function test_the_conditional_append_is_a_span_naming_the_stream_and_what_it_wrote(): void
    {
        $exporter = new InMemoryExporter();
        $ecotone = $this->bootstrapWithDynamicConsistencyBoundary($exporter);

        $ecotone->sendCommand(new EnrolStudent('course-1', 'student-1'));

        $span = $this->spanNamed($exporter, 'Conditional Append: ecotone_event_stream');

        self::assertSame('course:course-1', $span->getAttributes()->get(DynamicConsistencyBoundarySpanAttributes::TAGS));
        self::assertSame(1, $span->getAttributes()->get(DynamicConsistencyBoundarySpanAttributes::EVENTS_APPENDED));
        self::assertSame(StatusCode::STATUS_OK, $span->getStatus()->getCode());
    }

    public function test_a_conflict_is_recorded_on_the_append_span_as_an_error_with_a_dcb_conflict_event(): void
    {
        $exporter = new InMemoryExporter();
        $ecotone = $this->bootstrapWithDynamicConsistencyBoundary($exporter);

        try {
            $ecotone->sendCommand(new EnrolStudentTwice('course-1', 'student-1'));
            $this->fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException) {
        }

        $span = $this->spanCarryingTheConflictEvent($exporter);

        self::assertSame('Conditional Append: ecotone_event_stream', $span->getName());
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());

        $conflictEvent = $this->eventNamed($span, DynamicConsistencyBoundarySpanAttributes::CONFLICT_EVENT_NAME);

        self::assertSame('course:course-1', $conflictEvent->getAttributes()->get(DecisionModelConcurrencyException::CONFLICT_TAG_FIELD));
        self::assertSame(0, $conflictEvent->getAttributes()->get(DecisionModelConcurrencyException::CONFLICT_EXPECTED_VERSION_FIELD));
        self::assertSame(1, $conflictEvent->getAttributes()->get(DecisionModelConcurrencyException::CONFLICT_CURRENT_VERSION_FIELD));
        self::assertSame(CourseCapacity::class, $conflictEvent->getAttributes()->get(DecisionModelConcurrencyException::CONFLICT_MODEL_FIELD));
    }

    public function test_no_boundary_spans_are_produced_when_the_boundary_is_switched_off(): void
    {
        $exporter = new InMemoryExporter();
        $ecotone = $this->bootstrapWithoutDynamicConsistencyBoundary($exporter);

        $ecotone->sendCommandWithRouting('enrolment.withoutBoundary', 'course-1');

        foreach ($this->spanNames($exporter) as $spanName) {
            self::assertStringNotContainsString('Decision Models: ', $spanName);
            self::assertStringNotContainsString('Conditional Append: ', $spanName);
        }
    }

    private function spanNamed(InMemoryExporter $exporter, string $spanName): SpanDataInterface
    {
        /** @var SpanDataInterface $span */
        foreach ($exporter->getSpans() as $span) {
            if ($span->getName() === $spanName) {
                return $span;
            }
        }

        self::fail('No span named ' . $spanName . '. Spans: ' . implode(', ', $this->spanNames($exporter)));
    }

    private function spanCarryingTheConflictEvent(InMemoryExporter $exporter): SpanDataInterface
    {
        /** @var SpanDataInterface $span */
        foreach ($exporter->getSpans() as $span) {
            foreach ($span->getEvents() as $event) {
                if ($event->getName() === DynamicConsistencyBoundarySpanAttributes::CONFLICT_EVENT_NAME) {
                    return $span;
                }
            }
        }

        self::fail('No span carries a ' . DynamicConsistencyBoundarySpanAttributes::CONFLICT_EVENT_NAME . ' event. Spans: ' . implode(', ', $this->spanNames($exporter)));
    }

    private function eventNamed(SpanDataInterface $span, string $eventName): EventInterface
    {
        foreach ($span->getEvents() as $event) {
            if ($event->getName() === $eventName) {
                return $event;
            }
        }

        self::fail('No span event named ' . $eventName . ' on ' . $span->getName());
    }

    /**
     * @return string[]
     */
    private function spanNames(InMemoryExporter $exporter): array
    {
        return array_map(static fn (SpanDataInterface $span): string => $span->getName(), $exporter->getSpans());
    }

    private function bootstrapWithDynamicConsistencyBoundary(InMemoryExporter $exporter): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [Enrolments::class, CourseCapacity::class, CourseDefined::class, StudentEnrolled::class],
            containerOrAvailableServices: [new Enrolments(), TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::TRACING_PACKAGE])
                ->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }

    private function bootstrapWithoutDynamicConsistencyBoundary(InMemoryExporter $exporter): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [EnrolmentsWithoutBoundary::class],
            containerOrAvailableServices: [new EnrolmentsWithoutBoundary(), TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter)],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::TRACING_PACKAGE]),
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
