<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\EventSourcing\TaggedEventStore;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\Dbal\EventStreamSchemaFactory;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

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
        $store = $this->bootstrapTaggedEventStore();

        $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')]);

        $loaded = $store->load(EventCriteria::tag('course', 'course-1'));

        self::assertCount(1, $loaded->events);
        self::assertSame('course-1', $loaded->events[0]->getPayload()->courseId);
    }

    public function test_and_criteria_requires_all_tags_to_be_present(): void
    {
        $store = $this->bootstrapTaggedEventStore();

        $store->appendTo(self::STREAM, [
            new StudentSubscribedForDbalLoadTest('course-1', 'student-1'),
            new StudentSubscribedForDbalLoadTest('course-1', 'student-2'),
        ]);

        $loaded = $store->load(EventCriteria::tag('course', 'course-1')->andTag('student', 'student-2'));

        self::assertCount(1, $loaded->events);
        self::assertSame('student-2', $loaded->events[0]->getPayload()->studentId);
    }

    public function test_or_criteria_returns_events_matching_either(): void
    {
        $store = $this->bootstrapTaggedEventStore();

        $store->appendTo(self::STREAM, [
            new StudentSubscribedForDbalLoadTest('course-1', 'student-1'),
            new StudentSubscribedForDbalLoadTest('course-2', 'student-2'),
        ]);

        $loaded = $store->load(
            EventCriteria::tag('course', 'course-1'),
            EventCriteria::tag('course', 'course-2'),
        );

        self::assertCount(2, $loaded->events);
    }

    public function test_type_filter_excludes_other_event_types(): void
    {
        $store = $this->bootstrapTaggedEventStore();

        $store->appendTo(self::STREAM, [
            new StudentSubscribedForDbalLoadTest('course-1', 'student-1'),
            new CourseCapacityChangedForDbalLoadTest('course-1', 10),
        ]);

        $loaded = $store->load(EventCriteria::tag('course', 'course-1')->ofTypes(CourseCapacityChangedForDbalLoadTest::class));

        self::assertCount(1, $loaded->events);
        self::assertInstanceOf(CourseCapacityChangedForDbalLoadTest::class, $loaded->events[0]->getPayload());
    }

    public function test_event_matching_two_criteria_is_returned_once(): void
    {
        $store = $this->bootstrapTaggedEventStore();

        $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')]);

        $loaded = $store->load(
            EventCriteria::tag('course', 'course-1'),
            EventCriteria::tag('student', 'student-1'),
        );

        self::assertCount(1, $loaded->events);
    }

    public function test_conditional_append_succeeds_when_condition_still_valid(): void
    {
        $store = $this->bootstrapTaggedEventStore();
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 10)]);

        $loaded = $store->load(EventCriteria::tag('course', 'course-1'));
        $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')], $loaded->appendCondition);

        $reloaded = $store->load(EventCriteria::tag('course', 'course-1'));
        self::assertCount(2, $reloaded->events);
    }

    public function test_conditional_append_fails_when_tag_moved_since_capture(): void
    {
        $store = $this->bootstrapTaggedEventStore();
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 10)]);

        $loaded = $store->load(EventCriteria::tag('course', 'course-1'));

        $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 5)]);

        $this->expectException(DecisionModelConcurrencyException::class);
        $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')], $loaded->appendCondition);
    }

    public function test_unconditional_append_invalidates_a_held_condition(): void
    {
        $store = $this->bootstrapTaggedEventStore();
        $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 10)]);

        $loaded = $store->load(EventCriteria::tag('course', 'course-1'));

        $store->appendTo(self::STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 11)]);

        $this->expectException(DecisionModelConcurrencyException::class);
        $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')], $loaded->appendCondition);
    }

    public function test_delete_clears_the_index_so_load_returns_nothing(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $store = $ecotone->getServiceFromContainer(TaggedEventStore::class);

        $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')]);
        $ecotone->getGateway(\Ecotone\EventSourcing\EventStore::class)->delete(self::STREAM);

        $loaded = $store->load(EventCriteria::tag('course', 'course-1'));
        self::assertCount(0, $loaded->events);
    }

    public function test_model_fed_from_two_streams_folds_in_tag_version_order(): void
    {
        $ecotone = $this->bootstrapEcotone();
        $store = $ecotone->getServiceFromContainer(TaggedEventStore::class);
        $eventStore = $ecotone->getGateway(\Ecotone\EventSourcing\EventStore::class);

        $connection = $this->getConnection();
        foreach (EventStreamSchemaFactory::for($connection)->createTableSql(self::OTHER_STREAM) as $statement) {
            $connection->executeStatement($statement);
        }
        $eventStore->create(self::OTHER_STREAM, [new CourseCapacityChangedForDbalLoadTest('course-1', 10)]);
        $store->appendTo(self::STREAM, [new StudentSubscribedForDbalLoadTest('course-1', 'student-1')]);

        $loaded = $store->load(EventCriteria::tag('course', 'course-1'));

        self::assertCount(2, $loaded->events);
        self::assertInstanceOf(CourseCapacityChangedForDbalLoadTest::class, $loaded->events[0]->getPayload());
        self::assertInstanceOf(StudentSubscribedForDbalLoadTest::class, $loaded->events[1]->getPayload());
    }

    private function bootstrapTaggedEventStore(): TaggedEventStore
    {
        return $this->bootstrapEcotone()->getServiceFromContainer(TaggedEventStore::class);
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [StudentSubscribedForDbalLoadTest::class, CourseCapacityChangedForDbalLoadTest::class, EventsConverterForDbalLoadTest::class],
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForDbalLoadTest()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
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
