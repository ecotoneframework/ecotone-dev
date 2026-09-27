<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\Config\DatabaseTransaction\TransactionStatusTracker;
use Ecotone\Test\LicenceTesting;
use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

/**
 * licence Enterprise
 * @internal
 */
final class DecisionModelLoadTransactionOrderingDbalTest extends EventSourcingMessagingTestCase
{
    private const CLASSES = [
        SubscribeHandlerForOrderingTest::class,
        CourseForOrderingTest::class,
        CourseDefinedForOrderingTest::class,
        StudentSubscribedForOrderingTest::class,
        EventsConverterForOrderingTest::class,
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

    public function test_the_batched_decision_model_load_runs_inside_the_same_database_transaction_as_the_append(): void
    {
        TransactionStatusSpyForOrderingTest::$wasInsideTransactionDuringLoad = null;

        $ecotone = $this->bootstrapEcotone();
        $ecotone->getGateway(EventStore::class)->appendTo('ecotone_event_stream', [new CourseDefinedForOrderingTest('course-1', 5)]);

        $ecotone->sendCommand(new SubscribeToCourseForOrderingTest('course-1', 'student-1'));

        self::assertTrue(
            TransactionStatusSpyForOrderingTest::$wasInsideTransactionDuringLoad,
            'Expected the batched decision-model load to run after the DBAL command-bus transaction had begun, the same way the append does.',
        );
    }

    private function bootstrapEcotone(): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: self::CLASSES,
            containerOrAvailableServices: [self::getConnectionFactory(), new EventsConverterForOrderingTest(), new SubscribeHandlerForOrderingTest()],
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

final readonly class SubscribeToCourseForOrderingTest
{
    public function __construct(
        public string $courseId,
        public string $studentId,
    ) {
    }
}

final readonly class CourseDefinedForOrderingTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public int $capacity,
    ) {
    }
}

final readonly class StudentSubscribedForOrderingTest
{
    public function __construct(
        #[EventTag('course')] public string $courseId,
        public string $studentId,
    ) {
    }
}

#[DecisionModel]
final class CourseForOrderingTest
{
    private int $capacity = 0;

    #[EventSourcingHandler]
    public function defined(CourseDefinedForOrderingTest $event, #[Reference] TransactionStatusTracker $tracker): void
    {
        $this->capacity = $event->capacity;

        TransactionStatusSpyForOrderingTest::$wasInsideTransactionDuringLoad = $tracker->isInsideTransaction();
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}

final class SubscribeHandlerForOrderingTest
{
    #[CommandHandler]
    public function subscribe(SubscribeToCourseForOrderingTest $command, CourseForOrderingTest $course): array
    {
        return [new StudentSubscribedForOrderingTest($command->courseId, $command->studentId)];
    }
}

final class TransactionStatusSpyForOrderingTest
{
    public static ?bool $wasInsideTransactionDuringLoad = null;
}

final class EventsConverterForOrderingTest
{
    #[Converter]
    public function fromCourseDefined(CourseDefinedForOrderingTest $event): array
    {
        return ['courseId' => $event->courseId, 'capacity' => $event->capacity];
    }

    #[Converter]
    public function toCourseDefined(array $event): CourseDefinedForOrderingTest
    {
        return new CourseDefinedForOrderingTest($event['courseId'], $event['capacity']);
    }

    #[Converter]
    public function fromStudentSubscribed(StudentSubscribedForOrderingTest $event): array
    {
        return ['courseId' => $event->courseId, 'studentId' => $event->studentId];
    }

    #[Converter]
    public function toStudentSubscribed(array $event): StudentSubscribedForOrderingTest
    {
        return new StudentSubscribedForOrderingTest($event['courseId'], $event['studentId']);
    }
}
