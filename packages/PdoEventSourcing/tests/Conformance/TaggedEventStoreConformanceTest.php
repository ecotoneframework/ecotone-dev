<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Conformance;

use Closure;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\ConcurrencyException;
use Ecotone\Modelling\Event;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Throwable;

/**
 * licence Enterprise
 * @internal
 */
final class TaggedEventStoreConformanceTest extends EventSourcingMessagingTestCase
{
    private const KNOWN_DIVERGENCES = [
        'T1' => [
            'sides' => 'the in-memory store of EventSourcingConfiguration::createInMemory() — the default of bootstrapFlowTestingWithEventStore() — sits behind SerializingEventStore, so it sees array payloads and indexes no #[EventTag]: tagged loads return nothing and no condition ever conflicts; DBAL indexes and guards them',
            'cases' => [
                'test_events_carrying_a_tag_load_in_the_order_they_were_appended' => ['in-memory'],
                'test_tag_values_differing_only_in_case_are_distinct' => ['in-memory'],
                'test_every_tag_of_a_narrowed_criterion_must_be_carried' => ['in-memory'],
                'test_an_event_matching_several_combined_criteria_loads_once' => ['in-memory'],
                'test_an_event_type_filter_excludes_the_other_types_carrying_the_tag' => ['in-memory'],
                'test_loading_after_a_tag_sequence_skips_the_events_up_to_it' => ['in-memory'],
                'test_events_of_two_streams_load_in_the_order_their_tag_was_appended' => ['in-memory'],
                'test_a_condition_captured_by_a_load_lets_the_next_append_through' => ['in-memory'],
                'test_a_condition_is_rejected_once_its_tag_moved_and_appends_nothing' => ['in-memory'],
                'test_a_condition_captured_by_an_empty_load_is_rejected_once_the_tag_appears' => ['in-memory'],
                'test_a_condition_guards_its_tag_even_when_the_appended_events_carry_another_value' => ['in-memory'],
                'test_appending_no_events_under_a_stale_condition_changes_nothing' => ['in-memory'],
                'test_a_deleted_stream_no_longer_contributes_to_tag_loads' => ['in-memory'],
            ],
        ],
    ];

    public const SECOND_STREAM = 'tagged_conformance_second_stream';
    private const STREAM = 'ecotone_event_stream';

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'in-memory-without-event-sourcing-module' => ['in-memory-without-event-sourcing-module'];
        yield 'dbal' => ['dbal'];
    }

    public function test_every_known_divergence_names_a_case_of_this_suite(): void
    {
        $recordedCases = array_merge(...array_map(
            static fn (array $knownDivergence): array => array_keys($knownDivergence['cases']),
            array_values(self::KNOWN_DIVERGENCES),
        ));

        self::assertSame([], array_values(array_diff($recordedCases, get_class_methods($this))));
    }

    #[DataProvider('implementations')]
    public function test_events_carrying_a_tag_load_in_the_order_they_were_appended(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-1', 'student-2')]);
            $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-2', 'student-9')]);
            $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-1', 'student-1')]);

            self::assertEquals(
                [new StudentEnrolledForTaggedConformance('course-1', 'student-2'), new StudentEnrolledForTaggedConformance('course-1', 'student-1')],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_a_tag_no_event_carries_loads_as_empty(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-1', 'student-1')]);

            self::assertSame([], $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-404'))->events);
        });
    }

    #[DataProvider('implementations')]
    public function test_a_criterion_without_tags_loads_nothing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-1', 'student-1')]);

            $loaded = $eventStore->loadByCriteria(EventCriteria::any());

            self::assertSame([], $loaded->events);
            self::assertTrue($loaded->appendCondition->isEmpty());
        });
    }

    #[DataProvider('implementations')]
    public function test_tag_values_differing_only_in_case_are_distinct(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('COURSE-1', 'student-1')]);
            $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-1', 'student-2')]);

            self::assertEquals(
                [new StudentEnrolledForTaggedConformance('course-1', 'student-2')],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_every_tag_of_a_narrowed_criterion_must_be_carried(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [
                new StudentEnrolledForTaggedConformance('course-1', 'student-1'),
                new StudentEnrolledForTaggedConformance('course-1', 'student-2'),
                new StudentEnrolledForTaggedConformance('course-2', 'student-2'),
            ]);

            self::assertEquals(
                [new StudentEnrolledForTaggedConformance('course-1', 'student-2')],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1')->andTag('student', 'student-2'))->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_an_event_matching_several_combined_criteria_loads_once(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [
                new StudentEnrolledForTaggedConformance('course-1', 'student-1'),
                new StudentEnrolledForTaggedConformance('course-2', 'student-2'),
                new StudentEnrolledForTaggedConformance('course-3', 'student-3'),
            ]);

            self::assertEquals(
                [new StudentEnrolledForTaggedConformance('course-1', 'student-1'), new StudentEnrolledForTaggedConformance('course-2', 'student-2')],
                $this->payloadsOf($eventStore->loadByCriteria(
                    EventCriteria::tag('course', 'course-1')
                        ->or(EventCriteria::tag('student', 'student-1'))
                        ->or(EventCriteria::tag('course', 'course-2')),
                )->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_an_event_type_filter_excludes_the_other_types_carrying_the_tag(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [
                new StudentEnrolledForTaggedConformance('course-1', 'student-1'),
                new CourseCapacityChangedForTaggedConformance('course-1', 10),
            ]);

            self::assertEquals(
                [new CourseCapacityChangedForTaggedConformance('course-1', 10)],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1')->ofTypes(CourseCapacityChangedForTaggedConformance::class))->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_loading_after_a_tag_sequence_skips_the_events_up_to_it(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 10)]);
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 20)]);
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 30)]);

            self::assertEquals(
                [new CourseCapacityChangedForTaggedConformance('course-1', 20), new CourseCapacityChangedForTaggedConformance('course-1', 30)],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1')->afterTagSequence(1))->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_events_of_two_streams_load_in_the_order_their_tag_was_appended(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::SECOND_STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 10)]);
            $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-1', 'student-1')]);
            $this->append($eventStore, self::SECOND_STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 20)]);

            self::assertEquals(
                [
                    new CourseCapacityChangedForTaggedConformance('course-1', 10),
                    new StudentEnrolledForTaggedConformance('course-1', 'student-1'),
                    new CourseCapacityChangedForTaggedConformance('course-1', 20),
                ],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_a_condition_captured_by_a_load_lets_the_next_append_through(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 10)]);
            $loaded = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));

            $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-1', 'student-1')], $loaded);

            self::assertCount(2, $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events);
        });
    }

    #[DataProvider('implementations')]
    public function test_a_condition_is_rejected_once_its_tag_moved_and_appends_nothing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 10)]);
            $loaded = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 5)]);

            try {
                $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-1', 'student-1')], $loaded);
                self::fail('Expected the stale condition to be rejected');
            } catch (DecisionModelConcurrencyException) {
            }

            self::assertEquals(
                [new CourseCapacityChangedForTaggedConformance('course-1', 10), new CourseCapacityChangedForTaggedConformance('course-1', 5)],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_a_condition_captured_by_an_empty_load_is_rejected_once_the_tag_appears(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $loaded = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 10)]);

            try {
                $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-1', 'student-1')], $loaded);
                self::fail('Expected the condition captured before the tag appeared to be rejected');
            } catch (DecisionModelConcurrencyException) {
            }

            self::assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events);
        });
    }

    #[DataProvider('implementations')]
    public function test_a_condition_guards_its_tag_even_when_the_appended_events_carry_another_value(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 10)]);
            $loaded = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 5)]);

            try {
                $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-2', 5)], $loaded);
                self::fail('Expected the stale condition to be rejected');
            } catch (DecisionModelConcurrencyException) {
            }

            self::assertSame([], $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-2'))->events);
        });
    }

    #[DataProvider('implementations')]
    public function test_appending_no_events_under_a_stale_condition_changes_nothing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 10)]);
            $loaded = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 5)]);

            $this->append($eventStore, self::STREAM, [], $loaded);

            self::assertCount(2, $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events);
        });
    }

    #[DataProvider('implementations')]
    public function test_a_deleted_stream_no_longer_contributes_to_tag_loads(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::SECOND_STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 10)]);
            $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-1', 'student-1')]);

            $eventStore->delete(self::SECOND_STREAM);

            self::assertEquals(
                [new StudentEnrolledForTaggedConformance('course-1', 'student-1')],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_appending_a_tagged_event_under_a_stale_aggregate_version_is_rejected(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            self::inTransaction(fn () => $eventStore->appendTo(self::STREAM, [
                $this->courseEvent(new CourseCapacityChangedForTaggedConformance('course-1', 10), 1),
                $this->courseEvent(new CourseCapacityChangedForTaggedConformance('course-1', 20), 2),
            ]));

            try {
                self::inTransaction(fn () => $eventStore->appendTo(
                    self::STREAM,
                    [$this->courseEvent(new CourseCapacityChangedForTaggedConformance('course-1', 30), 3)],
                    AppendCondition::forAggregate('course', 'course-1', 1),
                ));
                self::fail('Expected the stale aggregate version to be rejected');
            } catch (ConcurrencyException $exception) {
                self::assertStringContainsString('Aggregate course course-1 was loaded at version 1, but it is at version 2 now', $exception->getMessage());
            }

            self::assertCount(2, $eventStore->load(self::STREAM));
        });
    }

    private function append(EventStore $eventStore, string $streamName, array $events, ?LoadedEvents $capturedBy = null): void
    {
        self::inTransaction(fn () => $eventStore->appendTo($streamName, $events, $capturedBy?->appendCondition));
    }

    private function conformanceCase(string $implementation, Closure $case): void
    {
        $target = $this->targetOf($implementation);
        $knownDivergence = $this->knownDivergenceOf($this->name(), $target);
        if ($knownDivergence === null) {
            $case($this->bootstrap($implementation));

            return;
        }

        try {
            $case($this->bootstrap($implementation));
        } catch (Throwable $divergence) {
            self::markTestSkipped("Known divergence {$knownDivergence['id']} on {$target}: {$knownDivergence['sides']}. This run: " . strtok($divergence->getMessage(), "\n"));
        }

        self::fail(
            "Known divergence {$knownDivergence['id']} is recorded for '{$knownDivergence['selector']}' on {$this->name()}, but the case now passes on {$target}. "
            . "Remove '{$knownDivergence['selector']}' from {$knownDivergence['id']} in KNOWN_DIVERGENCES, so the case runs there and a new divergence fails the suite again."
        );
    }

    /**
     * @return array{id: string, selector: string, sides: string}|null
     */
    private function knownDivergenceOf(string $case, string $target): ?array
    {
        foreach (self::KNOWN_DIVERGENCES as $id => $knownDivergence) {
            foreach ($knownDivergence['cases'][$case] ?? [] as $selector) {
                if ($selector === $target || str_starts_with($target, $selector . ':')) {
                    return ['id' => $id, 'selector' => $selector, 'sides' => $knownDivergence['sides']];
                }
            }
        }

        return null;
    }

    private function targetOf(string $implementation): string
    {
        if ($implementation !== 'dbal') {
            return $implementation;
        }

        $platform = $this->getConnection()->getDatabasePlatform();

        return 'dbal:' . match (true) {
            $platform instanceof MariaDBPlatform => 'mariadb',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform => 'pgsql',
            $platform instanceof SQLitePlatform => 'sqlite',
        };
    }

    private function bootstrap(string $implementation): EventStore
    {
        $converter = new class () {
            #[Converter]
            public function fromEnrolled(StudentEnrolledForTaggedConformance $event): array
            {
                return ['courseId' => $event->courseId, 'studentId' => $event->studentId];
            }

            #[Converter]
            public function toEnrolled(array $event): StudentEnrolledForTaggedConformance
            {
                return new StudentEnrolledForTaggedConformance($event['courseId'], $event['studentId']);
            }

            #[Converter]
            public function fromCapacityChanged(CourseCapacityChangedForTaggedConformance $event): array
            {
                return ['courseId' => $event->courseId, 'capacity' => $event->capacity];
            }

            #[Converter]
            public function toCapacityChanged(array $event): CourseCapacityChangedForTaggedConformance
            {
                return new CourseCapacityChangedForTaggedConformance($event['courseId'], $event['capacity']);
            }
        };
        $secondStream = new #[Stream(TaggedEventStoreConformanceTest::SECOND_STREAM)] class () {
        };

        if ($implementation === 'in-memory-without-event-sourcing-module') {
            return EcotoneLite::bootstrapFlowTesting(
                classesToResolve: [StudentEnrolledForTaggedConformance::class, CourseCapacityChangedForTaggedConformance::class, $converter::class, $secondStream::class],
                containerOrAvailableServices: [$converter],
                configuration: ServiceConfiguration::createWithDefaults()->withExtensionObjects([DynamicConsistencyBoundaryConfiguration::createWithDefaults()]),
                licenceKey: LicenceTesting::VALID_LICENCE,
            )->getGateway(EventStore::class);
        }

        return EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [StudentEnrolledForTaggedConformance::class, CourseCapacityChangedForTaggedConformance::class, $converter::class, $secondStream::class],
            containerOrAvailableServices: [$converter, DbalConnectionFactory::class => self::getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DynamicConsistencyBoundaryConfiguration::createWithDefaults(),
                    match ($implementation) {
                        'in-memory' => EventSourcingConfiguration::createInMemory(),
                        'dbal' => EventSourcingConfiguration::createWithDefaults(),
                    },
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->initializeDatabase()->getGateway(EventStore::class);
    }

    private function courseEvent(CourseCapacityChangedForTaggedConformance $payload, int $version): Event
    {
        return Event::create($payload, [
            MessageHeaders::EVENT_AGGREGATE_TYPE => 'course',
            MessageHeaders::EVENT_AGGREGATE_ID => $payload->courseId,
            MessageHeaders::EVENT_AGGREGATE_VERSION => $version,
        ]);
    }

    private function payloadsOf(array $events): array
    {
        return array_map(fn (object $event): object => $event->getPayload(), $events);
    }
}

final readonly class StudentEnrolledForTaggedConformance
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        #[EventTag('student')] public string $studentId,
    ) {
    }
}

final readonly class CourseCapacityChangedForTaggedConformance
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}
