<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Conformance;

use Closure;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\EventSourcing\AppendCondition;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\EventSourcing\EventStore;
use Ecotone\Api\EventSourcing\LoadedEvents;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ConcurrencyException;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Throwable;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class TaggedEventStoreConformanceTest extends EventSourcingMessagingTestCase
{
    private const KNOWN_DIVERGENCES = [];

    public const SECOND_STREAM = 'tagged_conformance_second_stream';
    public const STREAM = 'ecotone_event_stream';

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
            } catch (DecisionModelConcurrencyException $exception) {
                self::assertStringContainsString('another transaction appended an event carrying it', $exception->getMessage());
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

    #[DataProvider('implementations')]
    public function test_a_limited_load_returns_the_first_events_in_tag_sequence_order(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::SECOND_STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 10)]);
            $this->append($eventStore, self::STREAM, [new StudentEnrolledForTaggedConformance('course-1', 'student-1')]);
            $this->append($eventStore, self::SECOND_STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 20)]);

            self::assertEquals(
                [new CourseCapacityChangedForTaggedConformance('course-1', 10), new StudentEnrolledForTaggedConformance('course-1', 'student-1')],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1')->limit(2))->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_a_condition_tag_no_appended_event_carries_is_checked_without_being_moved(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 10)]);
            $loaded = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1'));

            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-2', 5)], $loaded);
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-3', 5)], $loaded);

            self::assertCount(1, $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-3'))->events);
        });
    }

    #[DataProvider('implementations')]
    public function test_a_tag_longer_than_one_page_is_read_page_by_page_after_the_last_tag_sequence(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            foreach ([10, 20, 30, 40, 50] as $capacity) {
                $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', $capacity)]);
            }

            $pages = [];
            $lastTagSequence = 0;
            do {
                $page = $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1')->afterTagSequence($lastTagSequence)->limit(2))->events;
                if ($page !== []) {
                    $pages[] = array_map(fn (object $payload): int => $payload->capacity, $this->payloadsOf($page));
                    $lastTagSequence += count($page);
                }
            } while ($page !== []);

            self::assertSame([[10, 20], [30, 40], [50]], $pages);
        });
    }

    #[DataProvider('implementations')]
    public function test_decisions_fetching_the_same_aggregate_read_only_both_append(string $implementation): void
    {
        $this->decisionCase($implementation, function (FlowTestSupport $deciding, FlowTestSupport $competing, object $seats): void {
            $deciding->sendCommandWithRouting('concert.schedule', 'c-1');
            $seats->arm(fn () => $competing->sendCommand(new HoldSeatForTaggedConformance('c-1', 'A2', 'v-1')));

            $deciding->sendCommand(new HoldSeatForTaggedConformance('c-1', 'A1', 'v-1'));

            self::assertSame(['A2', 'A1'], $this->heldSeatsOf($deciding));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_save_of_a_fetched_aggregate_between_read_and_append_fails_the_decision_naming_the_save(string $implementation): void
    {
        $this->decisionCase($implementation, function (FlowTestSupport $deciding, FlowTestSupport $competing, object $seats): void {
            $deciding->sendCommandWithRouting('concert.schedule', 'c-1');
            $seats->arm(fn () => $competing->sendCommandWithRouting('concert.rename', ['concertId' => 'c-1', 'name' => 'Encore']));

            try {
                $deciding->sendCommand(new HoldSeatForTaggedConformance('c-1', 'A1', 'v-1'));
                self::fail('Expected the decision over a concert saved meanwhile to be rejected');
            } catch (DecisionModelConcurrencyException $exception) {
                self::assertStringContainsString('concert c-1 changed since it was loaded', $exception->getMessage());
                self::assertStringContainsString('another transaction saved it or appended a #[DecisionModel(aggregate:)] decision over it', $exception->getMessage());
            }

            self::assertSame([], $this->heldSeatsOf($deciding));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_limit_counts_only_the_events_of_the_filtered_types(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            foreach ([
                new StudentEnrolledForTaggedConformance('course-1', 'student-1'),
                new StudentEnrolledForTaggedConformance('course-1', 'student-2'),
                new CourseCapacityChangedForTaggedConformance('course-1', 10),
                new StudentEnrolledForTaggedConformance('course-1', 'student-3'),
                new StudentEnrolledForTaggedConformance('course-1', 'student-4'),
                new CourseCapacityChangedForTaggedConformance('course-1', 20),
                new CourseCapacityChangedForTaggedConformance('course-1', 30),
            ] as $event) {
                $this->append($eventStore, self::STREAM, [$event]);
            }

            self::assertEquals(
                [new CourseCapacityChangedForTaggedConformance('course-1', 10), new CourseCapacityChangedForTaggedConformance('course-1', 20)],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1')->ofTypes(CourseCapacityChangedForTaggedConformance::class)->limit(2))->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_decisions_fetching_the_same_absent_aggregate_both_append_and_it_can_be_created_after(string $implementation): void
    {
        $this->decisionCase($implementation, function (FlowTestSupport $deciding, FlowTestSupport $competing, object $seats): void {
            $seats->arm(fn () => $competing->sendCommand(new HoldSeatForTaggedConformance('c-9', 'A2', 'v-1')));

            $deciding->sendCommand(new HoldSeatForTaggedConformance('c-9', 'A1', 'v-1'));
            $deciding->sendCommandWithRouting('concert.schedule', 'c-9');
            $deciding->sendCommand(new HoldSeatForTaggedConformance('c-9', 'A3', 'v-1'));

            self::assertSame(['A2', 'A1', 'A3'], $this->heldSeatsOf($deciding));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_creation_of_a_fetched_absent_aggregate_between_read_and_append_fails_the_decision_naming_the_save(string $implementation): void
    {
        $this->decisionCase($implementation, function (FlowTestSupport $deciding, FlowTestSupport $competing, object $seats): void {
            $seats->arm(fn () => $competing->sendCommandWithRouting('concert.schedule', 'c-9'));

            try {
                $deciding->sendCommand(new HoldSeatForTaggedConformance('c-9', 'A1', 'v-1'));
                self::fail('Expected the decision over a concert created meanwhile to be rejected');
            } catch (DecisionModelConcurrencyException $exception) {
                self::assertStringContainsString('another transaction saved it or appended a #[DecisionModel(aggregate:)] decision over it', $exception->getMessage());
            }

            self::assertSame([], $this->heldSeatsOf($deciding));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_page_never_splits_the_events_appended_together(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            $this->append($eventStore, self::STREAM, [
                new CourseCapacityChangedForTaggedConformance('course-1', 10),
                new CourseCapacityChangedForTaggedConformance('course-1', 20),
                new CourseCapacityChangedForTaggedConformance('course-1', 30),
            ]);
            $this->append($eventStore, self::STREAM, [new CourseCapacityChangedForTaggedConformance('course-1', 40)]);

            self::assertEquals(
                [new CourseCapacityChangedForTaggedConformance('course-1', 10), new CourseCapacityChangedForTaggedConformance('course-1', 20), new CourseCapacityChangedForTaggedConformance('course-1', 30)],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1')->limit(2))->events),
            );
            self::assertEquals(
                [new CourseCapacityChangedForTaggedConformance('course-1', 40)],
                $this->payloadsOf($eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1')->afterTagSequence(1)->limit(2))->events),
            );
        });
    }

    #[DataProvider('implementations')]
    public function test_decisions_reading_a_tag_no_appended_event_carries_both_append(string $implementation): void
    {
        $this->decisionCase($implementation, function (FlowTestSupport $deciding, FlowTestSupport $competing, object $seats): void {
            $deciding->sendCommandWithRouting('concert.schedule', 'c-1');
            $deciding->sendCommandWithRouting('venue.close', 'v-1');
            $seats->arm(fn () => $competing->sendCommand(new HoldSeatForTaggedConformance('c-1', 'A2', 'v-1')));

            $deciding->sendCommand(new HoldSeatForTaggedConformance('c-1', 'A1', 'v-1'));

            self::assertSame(['A2', 'A1'], $this->heldSeatsOf($deciding));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_limit_counts_only_the_events_carrying_every_narrowing_tag(string $implementation): void
    {
        $this->conformanceCase($implementation, function (EventStore $eventStore): void {
            foreach ([
                new StudentEnrolledForTaggedConformance('course-1', 'student-1'),
                new StudentEnrolledForTaggedConformance('course-1', 'student-2'),
                new StudentEnrolledForTaggedConformance('course-1', 'student-1'),
                new StudentEnrolledForTaggedConformance('course-2', 'student-1'),
                new StudentEnrolledForTaggedConformance('course-1', 'student-3'),
                new StudentEnrolledForTaggedConformance('course-1', 'student-1'),
            ] as $event) {
                $this->append($eventStore, self::STREAM, [$event]);
            }

            self::assertCount(2, $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1')->andTag('student', 'student-1')->limit(2))->events);
            self::assertCount(3, $eventStore->loadByCriteria(EventCriteria::tag('course', 'course-1')->andTag('student', 'student-1')->limit(5))->events);
        });
    }

    #[DataProvider('implementations')]
    public function test_an_append_carrying_a_decided_tag_between_read_and_append_fails_the_decision_naming_that_append(string $implementation): void
    {
        $this->decisionCase($implementation, function (FlowTestSupport $deciding, FlowTestSupport $competing, object $seats): void {
            $deciding->sendCommandWithRouting('concert.schedule', 'c-1');
            $seats->arm(fn () => $competing->sendCommandWithRouting('venue.close', 'v-1'));

            try {
                $deciding->sendCommand(new HoldSeatForTaggedConformance('c-1', 'A1', 'v-1'));
                self::fail('Expected the decision over a venue closed meanwhile to be rejected');
            } catch (DecisionModelConcurrencyException $exception) {
                self::assertStringContainsString('on tag venue:v-1', $exception->getMessage());
                self::assertStringContainsString('another transaction appended an event carrying it', $exception->getMessage());
            }

            self::assertSame([], $this->heldSeatsOf($deciding));
        });
    }

    #[DataProvider('implementations')]
    public function test_decisions_with_disjoint_boundaries_both_append_events_sharing_a_tag_neither_captured(string $implementation): void
    {
        $this->decisionCase($implementation, function (FlowTestSupport $deciding, FlowTestSupport $competing, object $seats): void {
            $deciding->sendCommandWithRouting('concert.schedule', 'c-1');
            $seats->arm(fn () => $competing->sendCommand(new HoldSeatForTaggedConformance('c-1', 'A2', 'v-1')));

            $deciding->sendCommand(new HoldSeatForTaggedConformance('c-1', 'A1', 'v-1'));

            self::assertSame(['A2', 'A1'], $this->heldSeatsOf($deciding));
            self::assertSame(2, $this->versionOfTag($deciding, 'concert', 'c-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_decision_scoped_by_a_tag_appended_meanwhile_without_being_captured_still_conflicts(string $implementation): void
    {
        $this->decisionCase($implementation, function (FlowTestSupport $deciding, FlowTestSupport $competing, object $seats): void {
            $deciding->sendCommandWithRouting('concert.schedule', 'c-1');
            $seats->arm(function () use ($competing): void {
                $competing->sendCommand(new HoldSeatForTaggedConformance('c-1', 'A1', 'v-1'));
                $competing->sendCommand(new HoldSeatForTaggedConformance('c-1', 'A2', 'v-1'));
            });

            try {
                $deciding->sendCommand(new CloseSalesForTaggedConformance('c-1'));
                self::fail('Expected closing sales on a stale seat count to be rejected');
            } catch (DecisionModelConcurrencyException $exception) {
                self::assertStringContainsString('on tag concert:c-1 (expected version 0, moved since it was read)', $exception->getMessage());
            }
        });
    }

    #[DataProvider('implementations')]
    public function test_decisions_over_the_same_aggregate_backed_model_still_conflict(string $implementation): void
    {
        $this->decisionCase($implementation, function (FlowTestSupport $deciding, FlowTestSupport $competing, object $seats): void {
            $deciding->sendCommandWithRouting('concert.schedule', 'c-1');
            $seats->arm(fn () => $competing->sendCommand(new AnnounceConcertForTaggedConformance('c-1', 'radio')));

            try {
                $deciding->sendCommand(new AnnounceConcertForTaggedConformance('c-1', 'press'));
                self::fail('Expected the second decision over the concert to be rejected');
            } catch (DecisionModelConcurrencyException $exception) {
                self::assertStringContainsString('concert c-1 changed since it was loaded', $exception->getMessage());
            }
        });
    }

    private function append(EventStore $eventStore, string $streamName, array $events, ?LoadedEvents $capturedBy = null): void
    {
        self::inTransaction(fn () => $eventStore->appendTo($streamName, $events, $capturedBy?->appendCondition));
    }

    private function conformanceCase(string $implementation, Closure $case): void
    {
        $this->runConformanceCase($implementation, fn () => $case($this->bootstrap($implementation)));
    }

    private function decisionCase(string $implementation, Closure $case): void
    {
        $this->runConformanceCase($implementation, function () use ($implementation, $case): void {
            $seats = $this->seatHolding();
            $deciding = $this->bootstrapDecisions($implementation, self::getConnectionFactory(), $seats);
            $competing = $this->sharesNoStoreWithASecondConnection($implementation)
                ? $deciding
                : $this->bootstrapDecisions($implementation, new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone'), $this->seatHolding());

            $case($deciding, $competing, $seats);
        });
    }

    private function runConformanceCase(string $implementation, Closure $run): void
    {
        $target = $this->targetOf($implementation);
        $knownDivergence = $this->knownDivergenceOf($this->name(), $target);
        if ($knownDivergence === null) {
            $run();

            return;
        }

        try {
            $run();
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
                extensionObjects: [DynamicConsistencyBoundaryConfiguration::createWithDefaults()],
                licenceKey: LicenceTesting::VALID_LICENCE,
                testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            )->getGateway(EventStore::class);
        }

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [StudentEnrolledForTaggedConformance::class, CourseCapacityChangedForTaggedConformance::class, $converter::class, $secondStream::class],
            containerOrAvailableServices: [$converter, DbalConnectionFactory::class => self::getConnectionFactory()],
            extensionObjects: [
                DynamicConsistencyBoundaryConfiguration::createWithDefaults(),
                match($implementation) {
                    'in-memory' => EventSourcingConfiguration::createInMemory(),'dbal' => EventSourcingConfiguration::createWithDefaults(),
                },
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
        )->setupDatabaseTables()->getGateway(EventStore::class);
    }

    private function sharesNoStoreWithASecondConnection(string $implementation): bool
    {
        return $implementation !== 'dbal' || $this->getConnection()->getDatabasePlatform() instanceof SQLitePlatform;
    }

    private function seatHolding(): object
    {
        return new class () {
            private ?Closure $midDecision = null;

            public function arm(Closure $midDecision): void
            {
                $this->midDecision = $midDecision;
            }

            #[CommandHandler]
            public function hold(
                HoldSeatForTaggedConformance $command,
                #[Fetch('payload.concertId')] ?ConcertForTaggedConformance $concert,
                SeatHoldsForTaggedConformance $seat,
                VenueClosuresForTaggedConformance $venue,
            ): array {
                $this->runMidDecisionOnce();

                return $seat->held ? [] : [new SeatHeldForTaggedConformance($command->concertId, $command->seatId)];
            }

            #[CommandHandler]
            public function announce(AnnounceConcertForTaggedConformance $command, ConcertAnnouncementsForTaggedConformance $announcements): array
            {
                $this->runMidDecisionOnce();

                return [new ConcertAnnouncedForTaggedConformance($command->concertId, $command->channel)];
            }

            #[CommandHandler]
            public function closeSales(CloseSalesForTaggedConformance $command, ConcertSalesForTaggedConformance $sales): array
            {
                $this->runMidDecisionOnce();

                return [new SalesClosedForTaggedConformance($command->concertId, $sales->seatsHeld)];
            }

            #[CommandHandler('venue.close')]
            public function closeVenue(string $venueId, #[Reference] EventStore $eventStore): void
            {
                $eventStore->appendTo(TaggedEventStoreConformanceTest::STREAM, [new VenueClosedForTaggedConformance($venueId, 'monday')]);
            }

            private function runMidDecisionOnce(): void
            {
                $midDecision = $this->midDecision;
                $this->midDecision = null;
                if ($midDecision !== null) {
                    $midDecision();
                }
            }
        };
    }

    private function bootstrapDecisions(string $implementation, DbalConnectionFactory $connectionFactory, object $seats): FlowTestSupport
    {
        $converter = new class () {
            #[Converter]
            public function fromScheduled(ConcertScheduledForTaggedConformance $event): array
            {
                return ['concertId' => $event->concertId];
            }

            #[Converter]
            public function toScheduled(array $event): ConcertScheduledForTaggedConformance
            {
                return new ConcertScheduledForTaggedConformance($event['concertId']);
            }

            #[Converter]
            public function fromRenamed(ConcertRenamedForTaggedConformance $event): array
            {
                return ['concertId' => $event->concertId, 'name' => $event->name];
            }

            #[Converter]
            public function toRenamed(array $event): ConcertRenamedForTaggedConformance
            {
                return new ConcertRenamedForTaggedConformance($event['concertId'], $event['name']);
            }

            #[Converter]
            public function fromAnnounced(ConcertAnnouncedForTaggedConformance $event): array
            {
                return ['concertId' => $event->concertId, 'channel' => $event->channel];
            }

            #[Converter]
            public function toAnnounced(array $event): ConcertAnnouncedForTaggedConformance
            {
                return new ConcertAnnouncedForTaggedConformance($event['concertId'], $event['channel']);
            }

            #[Converter]
            public function fromSeatHeld(SeatHeldForTaggedConformance $event): array
            {
                return ['concertId' => $event->concertId, 'seatId' => $event->seatId];
            }

            #[Converter]
            public function toSeatHeld(array $event): SeatHeldForTaggedConformance
            {
                return new SeatHeldForTaggedConformance($event['concertId'], $event['seatId']);
            }

            #[Converter]
            public function fromSalesClosed(SalesClosedForTaggedConformance $event): array
            {
                return ['concertId' => $event->concertId, 'seatsHeld' => $event->seatsHeld];
            }

            #[Converter]
            public function toSalesClosed(array $event): SalesClosedForTaggedConformance
            {
                return new SalesClosedForTaggedConformance($event['concertId'], $event['seatsHeld']);
            }

            #[Converter]
            public function fromVenueClosed(VenueClosedForTaggedConformance $event): array
            {
                return ['venueId' => $event->venueId, 'day' => $event->day];
            }

            #[Converter]
            public function toVenueClosed(array $event): VenueClosedForTaggedConformance
            {
                return new VenueClosedForTaggedConformance($event['venueId'], $event['day']);
            }
        };
        $classesToResolve = [
            ConcertForTaggedConformance::class, ConcertScheduledForTaggedConformance::class, ConcertRenamedForTaggedConformance::class,
            ConcertAnnouncedForTaggedConformance::class, ConcertAnnouncementsForTaggedConformance::class,
            SeatHoldsForTaggedConformance::class, SeatHeldForTaggedConformance::class,
            ConcertSalesForTaggedConformance::class, SalesClosedForTaggedConformance::class,
            VenueClosuresForTaggedConformance::class, VenueClosedForTaggedConformance::class,
            $converter::class, $seats::class,
        ];

        if ($implementation === 'in-memory-without-event-sourcing-module') {
            return EcotoneLite::bootstrapFlowTesting(
                classesToResolve: $classesToResolve,
                containerOrAvailableServices: [$converter, $seats],
                extensionObjects: [DynamicConsistencyBoundaryConfiguration::createWithDefaults()],
                enabledModules: [],
                licenceKey: LicenceTesting::VALID_LICENCE,
                testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            );
        }

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: [$converter, $seats, DbalConnectionFactory::class => $connectionFactory],
            extensionObjects: [
                DynamicConsistencyBoundaryConfiguration::createWithDefaults(),
                match($implementation) {
                    'in-memory' => EventSourcingConfiguration::createInMemory(),'dbal' => EventSourcingConfiguration::createWithDefaults(),
                },
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
        )->setupDatabaseTables();
    }

    private function versionOfTag(FlowTestSupport $ecotone, string $tagName, string $tagValue): int
    {
        return $ecotone->getGateway(EventStore::class)->loadByCriteria(EventCriteria::tag($tagName, $tagValue))->appendCondition->expectedTagVersions()[0]['expectedVersion'];
    }

    /**
     * @return string[]
     */
    private function heldSeatsOf(FlowTestSupport $ecotone): array
    {
        return array_map(
            static fn (Event $event): string => $event->getPayload()->seatId,
            $ecotone->getGateway(EventStore::class)->loadByCriteria(
                EventCriteria::tag('seat', 'A1')->or(EventCriteria::tag('seat', 'A2'))->or(EventCriteria::tag('seat', 'A3')),
            )->events,
        );
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

final readonly class HoldSeatForTaggedConformance
{
    public function __construct(
        public string $concertId,
        public string $seatId,
        public string $venueId,
    ) {
    }
}

final readonly class AnnounceConcertForTaggedConformance
{
    public function __construct(
        public string $concertId,
        public string $channel,
    ) {
    }
}

final readonly class ConcertScheduledForTaggedConformance
{
    public function __construct(public string $concertId)
    {
    }
}

final readonly class ConcertRenamedForTaggedConformance
{
    public function __construct(
        public string $concertId,
        public string $name,
    ) {
    }
}

final readonly class ConcertAnnouncedForTaggedConformance
{
    public function __construct(
        public string $concertId,
        public string $channel,
    ) {
    }
}

final readonly class SeatHeldForTaggedConformance
{
    public function __construct(
        #[EventTag('concert')] public string $concertId,
        #[EventTag('seat')] public string $seatId,
    ) {
    }
}

final readonly class VenueClosedForTaggedConformance
{
    public function __construct(
        #[EventTag('venue')] public string $venueId,
        public string $day,
    ) {
    }
}

#[EventSourcingAggregate]
#[AggregateType('concert')]
final class ConcertForTaggedConformance
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $concertId;

    #[CommandHandler('concert.schedule')]
    public static function schedule(string $concertId): array
    {
        return [new ConcertScheduledForTaggedConformance($concertId)];
    }

    #[CommandHandler('concert.rename')]
    public function rename(array $command): array
    {
        return [new ConcertRenamedForTaggedConformance($this->concertId, $command['name'])];
    }

    #[Apply]
    public function scheduled(ConcertScheduledForTaggedConformance $event): void
    {
        $this->concertId = $event->concertId;
    }
}

#[DecisionModel(tags: ['seat'])]
final class SeatHoldsForTaggedConformance
{
    public bool $held = false;

    #[Apply]
    public function held(SeatHeldForTaggedConformance $event): void
    {
        $this->held = true;
    }
}

#[DecisionModel(tags: ['venue'])]
final class VenueClosuresForTaggedConformance
{
    public int $closures = 0;

    #[Apply]
    public function closed(VenueClosedForTaggedConformance $event): void
    {
        $this->closures++;
    }
}

final readonly class CloseSalesForTaggedConformance
{
    public function __construct(public string $concertId)
    {
    }
}

final readonly class SalesClosedForTaggedConformance
{
    public function __construct(
        #[EventTag('concert')] public string $concertId,
        public int $seatsHeld,
    ) {
    }
}

#[DecisionModel(tags: ['concert'])]
final class ConcertSalesForTaggedConformance
{
    public int $seatsHeld = 0;

    #[Apply]
    public function held(SeatHeldForTaggedConformance $event): void
    {
        $this->seatsHeld++;
    }
}

#[DecisionModel(aggregate: ConcertForTaggedConformance::class)]
final class ConcertAnnouncementsForTaggedConformance
{
    public int $scheduled = 0;

    #[Apply]
    public function scheduled(ConcertScheduledForTaggedConformance $event): void
    {
        $this->scheduled++;
    }
}
