<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Doctrine\DBAL\DriverManager;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Dbal\DbalConnection;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * Regression coverage for review finding M1: an injected handler's decision models used to each pay their own
 * loadByCriteria() round trip. DecisionModelLoadInterceptor now batches every model's criteria with
 * EventCriteria::or() into a single call, so a handler with N models costs the read-side statements of one,
 * not N of them -- exactly the design's "three models cost the same three statements as one".
 *
 * licence Enterprise
 * @internal
 */
final class DecisionModelLoadQueryCountDbalTest extends EventSourcingMessagingTestCase
{
    private const THREE_MODEL_CLASSES = [
        CourseSubscriptionsForQueryCountTest::class,
        CourseCapacityForQueryCountTest::class,
        StudentCoursesForQueryCountTest::class,
        StudentSubscriptionForQueryCountTest::class,
        CourseDefinedForQueryCountTest::class,
        CourseCapacityChangedForQueryCountTest::class,
        StudentSubscribedToCourseForQueryCountTest::class,
        EventsConverterForQueryCountTest::class,
    ];

    private const COUPON_AGGREGATE_CLASSES = [
        OrderForDbalCouponTest::class,
        CouponRedemptionsForDbalCouponTest::class,
        CustomerCouponUseForDbalCouponTest::class,
        CouponIssuedForDbalCouponTest::class,
        OrderPlacedForDbalCouponTest::class,
        CompetingWriteInjectorForDbalCouponTest::class,
        EventsConverterForDbalCouponTest::class,
    ];

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
    }

    public function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    public function test_a_three_model_handler_makes_exactly_one_batched_load_like_a_one_model_handler(): void
    {
        $ecotone = $this->bootstrapEcotone(self::THREE_MODEL_CLASSES);
        $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CourseDefinedForQueryCountTest('course-1', 5)]);

        QueryCountingDbalConnection::resetCount();
        $ecotone->sendCommand(new ChangeCourseCapacityForQueryCountTest('course-1', 4));

        self::assertSame(
            1,
            QueryCountingDbalConnection::$loadByCriteriaSelectCount,
            'Expected the single-model changeCapacity handler to make exactly one loadByCriteria() call.',
        );

        QueryCountingDbalConnection::resetCount();
        $ecotone->sendCommand(new SubscribeStudentToCourseForQueryCountTest('course-1', 'student-1'));

        self::assertSame(
            1,
            QueryCountingDbalConnection::$loadByCriteriaSelectCount,
            'Expected the three-model subscribe handler to still make exactly one loadByCriteria() call -- a batched single load, not one per injected model.',
        );
    }

    public function test_the_aggregate_injected_coupon_walkthrough_makes_one_batched_load(): void
    {
        $ecotone = $this->bootstrapEcotone(self::COUPON_AGGREGATE_CLASSES, [new EventsConverterForDbalCouponTest(), new CompetingWriteInjectorForDbalCouponTest()]);
        $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CouponIssuedForDbalCouponTest('SUMMER24', 5)]);

        QueryCountingDbalConnection::resetCount();
        $ecotone->sendCommand(new PlaceOrderForDbalCouponTest('o-1', 'alice', 'SUMMER24'));

        self::assertSame(
            1,
            QueryCountingDbalConnection::$loadByCriteriaSelectCount,
            'Expected the aggregate factory injecting two decision models (coupon, usage) to still make exactly one loadByCriteria() call, not one per model.',
        );
    }

    /**
     * @param class-string[] $classes
     * @param object[]|null $extraAvailableServices services beyond the counting connection factory itself
     */
    private function bootstrapEcotone(array $classes, ?array $extraAvailableServices = null): FlowTestSupport
    {
        $extraAvailableServices ??= [new CourseSubscriptionsForQueryCountTest(), new EventsConverterForQueryCountTest()];
        $baseConnection = self::getConnectionFactory()->createContext()->getDbalConnection();
        $params = $baseConnection->getParams();
        $params['wrapperClass'] = QueryCountingDbalConnection::class;
        $countingConnectionFactory = DbalConnection::create(DriverManager::getConnection($params));

        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: $classes,
            containerOrAvailableServices: [DbalConnectionFactory::class => $countingConnectionFactory, ...$extraAvailableServices],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withAutomaticTableInitialization(true),
                ])
                ->withCacheDirectoryPath(sys_get_temp_dir() . '/ecotone-test-' . uniqid()),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->initializeDatabase();

        return $ecotone;
    }

    private function dropTables(): void
    {
        $connection = $this->getConnection();
        foreach ([TagTableManager::TAGGED_EVENTS_TABLE, TagTableManager::TAG_VERSIONS_TABLE, 'ecotone_event_stream'] as $tableName) {
            if (self::tableExists($connection, $tableName)) {
                $connection->executeStatement('DROP TABLE ' . $tableName);
            }
        }
    }
}

final readonly class SubscribeStudentToCourseForQueryCountTest
{
    public function __construct(
        public string $courseId,
        public string $studentId,
    ) {
    }
}

final readonly class ChangeCourseCapacityForQueryCountTest
{
    public function __construct(
        public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class CourseDefinedForQueryCountTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class CourseCapacityChangedForQueryCountTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class StudentSubscribedToCourseForQueryCountTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        #[EventTag('student')] public string $studentId,
    ) {
    }
}

#[DecisionModel]
final class CourseCapacityForQueryCountTest
{
    private int $capacity = 0;
    private int $seatsTaken = 0;

    #[EventSourcingHandler]
    public function defined(CourseDefinedForQueryCountTest $event): void
    {
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler]
    public function capacityChanged(CourseCapacityChangedForQueryCountTest $event): void
    {
        $this->capacity = $event->capacity;
    }

    #[EventSourcingHandler]
    public function seatTaken(StudentSubscribedToCourseForQueryCountTest $event): void
    {
        $this->seatsTaken++;
    }

    public function hasFreeSeat(): bool
    {
        return $this->seatsTaken < $this->capacity;
    }

    public function seatsTaken(): int
    {
        return $this->seatsTaken;
    }
}

#[DecisionModel(tags: ['student'])]
final class StudentCoursesForQueryCountTest
{
    private int $courses = 0;

    #[EventSourcingHandler]
    public function joined(StudentSubscribedToCourseForQueryCountTest $event): void
    {
        $this->courses++;
    }

    public function canJoinAnother(): bool
    {
        return $this->courses < 5;
    }
}

#[DecisionModel]
final class StudentSubscriptionForQueryCountTest
{
    private bool $exists = false;

    #[EventSourcingHandler]
    public function subscribed(StudentSubscribedToCourseForQueryCountTest $event): void
    {
        $this->exists = true;
    }

    public function exists(): bool
    {
        return $this->exists;
    }
}

final class CourseSubscriptionsForQueryCountTest
{
    #[CommandHandler]
    public function subscribe(
        SubscribeStudentToCourseForQueryCountTest $command,
        CourseCapacityForQueryCountTest $course,
        StudentCoursesForQueryCountTest $student,
        StudentSubscriptionForQueryCountTest $subscription,
    ): array {
        if ($subscription->exists() || ! $course->hasFreeSeat() || ! $student->canJoinAnother()) {
            return [];
        }

        return [new StudentSubscribedToCourseForQueryCountTest($command->courseId, $command->studentId)];
    }

    #[CommandHandler]
    public function changeCapacity(ChangeCourseCapacityForQueryCountTest $command, CourseCapacityForQueryCountTest $course): array
    {
        return [new CourseCapacityChangedForQueryCountTest($command->courseId, $command->capacity)];
    }
}

final class EventsConverterForQueryCountTest
{
    #[Converter]
    public function fromCourseDefined(CourseDefinedForQueryCountTest $event): array
    {
        return ['courseId' => $event->courseId, 'capacity' => $event->capacity];
    }

    #[Converter]
    public function toCourseDefined(array $event): CourseDefinedForQueryCountTest
    {
        return new CourseDefinedForQueryCountTest($event['courseId'], $event['capacity']);
    }

    #[Converter]
    public function fromCourseCapacityChanged(CourseCapacityChangedForQueryCountTest $event): array
    {
        return ['courseId' => $event->courseId, 'capacity' => $event->capacity];
    }

    #[Converter]
    public function toCourseCapacityChanged(array $event): CourseCapacityChangedForQueryCountTest
    {
        return new CourseCapacityChangedForQueryCountTest($event['courseId'], $event['capacity']);
    }

    #[Converter]
    public function fromStudentSubscribed(StudentSubscribedToCourseForQueryCountTest $event): array
    {
        return ['courseId' => $event->courseId, 'studentId' => $event->studentId];
    }

    #[Converter]
    public function toStudentSubscribed(array $event): StudentSubscribedToCourseForQueryCountTest
    {
        return new StudentSubscribedToCourseForQueryCountTest($event['courseId'], $event['studentId']);
    }
}
