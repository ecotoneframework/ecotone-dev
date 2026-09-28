<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;
use Throwable;

/**
 * licence Enterprise
 * @internal
 */
final class DbalTaggedLoadTest extends EventSourcingMessagingTestCase
{
    private const STREAM = 'ecotone_event_stream';
    private const OTHER_STREAM = 'other_stream_for_load_test';

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTagTables();
    }

    public function tearDown(): void
    {
        $this->dropTagTables();
        parent::tearDown();
    }

    public function test_loading_events_by_tag_returns_the_event_that_carries_it(): void
    {
        $store = $this->bootstrapEventStore();

        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')]));

        $loaded = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        self::assertCount(1, $loaded->events);
        self::assertSame('course-1', $loaded->events[0]->getPayload()->courseId);
    }

    public function test_and_criteria_requires_all_tags_to_be_present(): void
    {
        $store = $this->bootstrapEventStore();

        self::inTransaction(fn () => $store->appendTo(self::STREAM, [
            new StudentSubscribedForDbalLoadTest('course-1', 'student-1'),
            new StudentSubscribedForDbalLoadTest('course-1', 'student-2'),
        ]));

        $loaded = $store->loadByCriteria(EventCriteria::tag('course', 'course-1')->andTag('student', 'student-2'));

        self::assertCount(1, $loaded->events);
        self::assertSame('student-2', $loaded->events[0]->getPayload()->studentId);
    }

    public function test_or_criteria_returns_events_matching_either(): void
    {
        $store = $this->bootstrapEventStore();

        self::inTransaction(fn () => $store->appendTo(self::STREAM, [
            new StudentSubscribedForDbalLoadTest('course-1', 'student-1'),
            new StudentSubscribedForDbalLoadTest('course-2', 'student-2'),
        ]));

        $loaded = $store->loadByCriteria(
            EventCriteria::tag('course', 'course-1')->or(EventCriteria::tag('course', 'course-2')),
        );

        self::assertCount(2, $loaded->events);
    }

    public function test_type_filter_excludes_other_event_types(): void
    {
        $store = $this->bootstrapEventStore();

        self::inTransaction(fn () => $store->appendTo(self::STREAM, [
            new StudentSubscribedForDbalLoadTest('course-1', 'student-1'),
            new CourseCapacityChangedForDbalLoadTest('course-1', 10),
        ]));

        $loaded = $store->loadByCriteria(EventCriteria::tag('course', 'course-1')->ofTypes(CourseCapacityChangedForDbalLoadTest::class));

        self::assertCount(1, $loaded->events);
        self::assertInstanceOf(CourseCapacityChangedForDbalLoadTest::class, $loaded->events[0]->getPayload());
    }

    public function test_event_matching_two_criteria_is_returned_once(): void
    {
        $store = $this->bootstrapEventStore();

        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')]));

        $loaded = $store->loadByCriteria(
            EventCriteria::tag('course', 'course-1')->or(EventCriteria::tag('student', 'student-1')),
        );

        self::assertCount(1, $loaded->events);
    }

    public function test_conditional_append_succeeds_when_condition_still_valid(): void
    {
        $store = $this->bootstrapEventStore();
        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 10)]));

        $loaded = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));
        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')], $loaded->appendCondition));

        $reloaded = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));
        self::assertCount(2, $reloaded->events);
    }

    public function test_conditional_append_fails_when_tag_moved_since_capture(): void
    {
        $store = $this->bootstrapEventStore();
        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 10)]));

        $loaded = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 5)]));

        $this->expectException(DecisionModelConcurrencyException::class);
        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')], $loaded->appendCondition));
    }

    public function test_appending_no_events_under_a_stale_condition_is_a_no_op(): void
    {
        $store = $this->bootstrapEventStore();
        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 10)]));
        $loaded = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));
        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 5)]));

        self::inTransaction(fn () => $store->appendTo(self::STREAM, [], $loaded->appendCondition));

        self::assertCount(2, $store->loadByCriteria(EventCriteria::tag('course', 'course-1'))->events);
    }

    public function test_loading_after_the_stream_table_was_dropped_names_the_stream_table_not_the_tag_tables(): void
    {
        $store = $this->bootstrapEventStore();
        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 10)]));
        $this->getConnection()->executeStatement('DROP TABLE ' . self::STREAM);

        try {
            $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));
            self::fail('Expected the missing stream table to be reported');
        } catch (Throwable $exception) {
            self::assertStringContainsString(self::STREAM, $exception->getMessage());
            self::assertStringNotContainsString(TagTableManager::TAG_VERSIONS_TABLE, $exception->getMessage());
        }
    }

    public function test_unconditional_append_invalidates_a_held_condition(): void
    {
        $store = $this->bootstrapEventStore();
        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 10)]));

        $loaded = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 11)]));

        $this->expectException(DecisionModelConcurrencyException::class);
        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')], $loaded->appendCondition));
    }

    public function test_delete_clears_the_index_so_load_returns_nothing(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $store = $ecotone->getGateway(EventStore::class);

        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')]));
        $store->delete(self::STREAM);

        $loaded = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));
        self::assertCount(0, $loaded->events);
    }

    public function test_model_fed_from_two_streams_folds_in_tag_version_order(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $store = $ecotone->getGateway(EventStore::class);

        $connection = $this->getConnection();
        foreach (EventStreamSchemaFactory::for($connection)->createTableSql(self::OTHER_STREAM) as $statement) {
            $connection->executeStatement($statement);
        }
        self::inTransaction(fn () => $store->create(self::OTHER_STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 10)]));
        self::inTransaction(fn () => $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')]));

        $loaded = $store->loadByCriteria(EventCriteria::tag('course', 'course-1'));

        self::assertCount(2, $loaded->events);
        self::assertInstanceOf(CourseCapacityChangedForDbalLoadTest::class, $loaded->events[0]->getPayload());
        self::assertInstanceOf(StudentSubscribedForDbalLoadTest::class, $loaded->events[1]->getPayload());
    }

    public function test_loading_events_by_criteria_through_the_gateway_returns_the_event_that_carries_it(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $store = $ecotone->getGateway(EventStore::class);

        self::inTransaction(fn () => $store->appendTo(self::STREAM, [
            new StudentSubscribedForDbalLoadTest('course-1', 'student-1'),
            new StudentSubscribedForDbalLoadTest('course-2', 'student-2'),
        ]));

        $loaded = $store->loadByCriteria(
            EventCriteria::tag('course', 'course-1')->or(EventCriteria::tag('course', 'course-2')),
        );

        self::assertCount(2, $loaded->events);
    }

    private function bootstrapEventStore(): EventStore
    {
        return $this->bootstrapEcotone()->getGateway(EventStore::class);
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [StudentSubscribedForDbalLoadTest::class, CourseCapacityChangedForDbalLoadTest::class, EventsConverterForDbalLoadTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForDbalLoadTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DynamicConsistencyBoundaryConfiguration::createWithDefaults(),
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true),
                ]),
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }

    private function dropTagTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, self::STREAM, self::OTHER_STREAM] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final readonly class StudentSubscribedForDbalLoadTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        #[EventTag('student')] public string $studentId,
    ) {
    }
}

final readonly class CourseCapacityChangedForDbalLoadTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}

final class EventsConverterForDbalLoadTest
{
    #[Converter]
    public function fromSubscribed(StudentSubscribedForDbalLoadTest $event): array
    {
        return ['courseId' => $event->courseId, 'studentId' => $event->studentId];
    }

    #[Converter]
    public function toSubscribed(array $event): StudentSubscribedForDbalLoadTest
    {
        return new StudentSubscribedForDbalLoadTest($event['courseId'], $event['studentId']);
    }

    #[Converter]
    public function fromCapacityChanged(CourseCapacityChangedForDbalLoadTest $event): array
    {
        return ['courseId' => $event->courseId, 'capacity' => $event->capacity];
    }

    #[Converter]
    public function toCapacityChanged(array $event): CourseCapacityChangedForDbalLoadTest
    {
        return new CourseCapacityChangedForDbalLoadTest($event['courseId'], $event['capacity']);
    }
}
