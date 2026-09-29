<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use function array_map;

use Closure;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\DecisionModel;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\Fetch;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DecisionModelConcurrencyException;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventCriteria;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\EventSourcing\Database\TagTableManager;
use Ecotone\EventSourcing\EventStore;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Modelling\Event;
use Ecotone\Test\LicenceTesting;

use function getenv;

use RuntimeException;

use function sys_get_temp_dir;

use Test\Ecotone\EventSourcing\EventSourcingMessagingTestCase;

use function uniqid;

/**
 * licence Enterprise
 * @internal
 */
final class UniqueUsernamePerTenantDbalTest extends EventSourcingMessagingTestCase
{
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

    public function test_the_first_claim_of_a_username_succeeds_when_no_counter_row_exists_yet(): void
    {
        $ecotone = $this->bootstrapEcotone(self::getConnectionFactory());

        $ecotone->sendCommand(new ClaimUsernameForDbalTest('ada'), metadata: ['tenant' => 'acme']);

        self::assertSame(['ada'], $this->claimedUsernamesOf($ecotone, 'acme'));
    }

    public function test_a_second_claim_committed_by_another_connection_mid_decision_conflicts_naming_the_model(): void
    {
        $this->skipUnlessTwoConnectionsCanRace();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()));
        $anna->getServiceFromContainer(CompetingClaimForDbalTest::class)->arm(
            fn () => $ben->sendCommand(new ClaimUsernameForDbalTest('ada'), metadata: ['tenant' => 'acme'])
        );

        try {
            $anna->sendCommand(new ClaimUsernameRacingForDbalTest('ada'), metadata: ['tenant' => 'acme']);
            $this->fail('Expected a DecisionModelConcurrencyException');
        } catch (DecisionModelConcurrencyException $exception) {
            self::assertStringContainsString('on tag username:ada', $exception->getMessage());
            self::assertStringContainsString('while deciding ' . UsernameAvailabilityForDbalTest::class, $exception->getMessage());
        }

        self::assertSame(['ada'], $this->claimedUsernamesOf($anna, 'acme'));
    }

    public function test_a_claim_of_another_username_in_the_same_tenant_does_not_conflict_on_the_filter_only_tenant_tag(): void
    {
        $this->skipUnlessTwoConnectionsCanRace();

        $anna = $this->bootstrapEcotone(self::getConnectionFactory());
        $ben = $this->bootstrapEcotone(new DbalConnectionFactory($this->dsn()));
        $anna->getServiceFromContainer(CompetingClaimForDbalTest::class)->arm(
            fn () => $ben->sendCommand(new ClaimUsernameForDbalTest('grace'), metadata: ['tenant' => 'acme'])
        );

        $anna->sendCommand(new ClaimUsernameRacingForDbalTest('ada'), metadata: ['tenant' => 'acme']);

        self::assertSame(['grace', 'ada'], $this->claimedUsernamesOf($anna, 'acme'));
    }

    public function test_claiming_a_username_already_taken_in_the_same_tenant_is_refused(): void
    {
        $ecotone = $this->bootstrapEcotone(self::getConnectionFactory());
        $ecotone->sendCommand(new ClaimUsernameForDbalTest('ada'), metadata: ['tenant' => 'acme']);

        $this->expectException(UsernameAlreadyTakenForDbalTest::class);

        $ecotone->sendCommand(new ClaimUsernameForDbalTest('ada'), metadata: ['tenant' => 'acme']);
    }

    public function test_a_released_username_can_be_claimed_again(): void
    {
        $ecotone = $this->bootstrapEcotone(self::getConnectionFactory());
        $ecotone->sendCommand(new ClaimUsernameForDbalTest('ada'), metadata: ['tenant' => 'acme']);
        $ecotone->sendCommand(new ReleaseUsernameForDbalTest('ada'), metadata: ['tenant' => 'acme']);

        $ecotone->sendCommand(new ClaimUsernameForDbalTest('ada'), metadata: ['tenant' => 'acme']);

        self::assertSame(['ada', 'ada'], $this->claimedUsernamesOf($ecotone, 'acme'));
    }

    public function test_the_same_username_in_another_tenant_does_not_conflict(): void
    {
        $ecotone = $this->bootstrapEcotone(self::getConnectionFactory());
        $ecotone->sendCommand(new ClaimUsernameForDbalTest('ada'), metadata: ['tenant' => 'acme']);

        $ecotone->sendCommand(new ClaimUsernameForDbalTest('ada'), metadata: ['tenant' => 'globex']);

        self::assertSame(['ada'], $this->claimedUsernamesOf($ecotone, 'acme'));
        self::assertSame(['ada'], $this->claimedUsernamesOf($ecotone, 'globex'));
    }

    /**
     * @return string[]
     */
    private function claimedUsernamesOf(FlowTestSupport $ecotone, string $tenant): array
    {
        return array_map(
            static fn (Event $event): string => $event->getPayload()->username,
            $ecotone->getGateway(EventStore::class)
                ->loadByCriteria(EventCriteria::tag('tenant', $tenant)->ofTypes(UsernameClaimedForDbalTest::class))
                ->events,
        );
    }

    private function bootstrapEcotone(DbalConnectionFactory $connectionFactory): FlowTestSupport
    {
        $ecotone = $this->bootstrapFlowTestingWithEventStore(
            classesToResolve: [
                UsernameAvailabilityForDbalTest::class,
                UsernameRegistrationForDbalTest::class,
                UsernameClaimedForDbalTest::class,
                UsernameReleasedForDbalTest::class,
                UsernameEventsConverterForDbalTest::class,
            ],
            containerOrAvailableServices: [
                $connectionFactory,
                new UsernameRegistrationForDbalTest(),
                new UsernameEventsConverterForDbalTest(),
                new CompetingClaimForDbalTest(),
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE])
                ->withExtensionObjects([
                    DynamicConsistencyBoundaryConfiguration::createWithDefaults()->withFilterOnlyTags(['tenant']),
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

    private function skipUnlessTwoConnectionsCanRace(): void
    {
        if (self::getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->markTestSkipped('SQLite cannot commit from a second connection while the first holds a write transaction.');
        }
    }

    private function dsn(): string
    {
        return getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
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

/**
 * @internal
 */
final class CompetingClaimForDbalTest
{
    private ?Closure $claim = null;

    public function arm(Closure $claim): void
    {
        $this->claim = $claim;
    }

    public function commitIfArmed(): void
    {
        $claim = $this->claim;
        $this->claim = null;

        if ($claim !== null) {
            $claim();
        }
    }
}

/**
 * @internal
 */
final readonly class ClaimUsernameForDbalTest
{
    public function __construct(public string $username)
    {
    }
}

/**
 * @internal
 */
final readonly class ClaimUsernameRacingForDbalTest
{
    public function __construct(public string $username)
    {
    }
}

/**
 * @internal
 */
final readonly class ReleaseUsernameForDbalTest
{
    public function __construct(public string $username)
    {
    }
}

/**
 * @internal
 */
final readonly class UsernameClaimedForDbalTest
{
    public function __construct(
        #[EventTag('tenant')] public string $tenant,
        #[EventTag('username')] public string $username,
    ) {
    }
}

/**
 * @internal
 */
final readonly class UsernameReleasedForDbalTest
{
    public function __construct(
        #[EventTag('tenant')] public string $tenant,
        #[EventTag('username')] public string $username,
    ) {
    }
}

/**
 * @internal
 */
final class UsernameAlreadyTakenForDbalTest extends RuntimeException
{
}

/**
 * @internal
 */
final class UsernameNotTakenForDbalTest extends RuntimeException
{
}

/**
 * @internal
 */
#[DecisionModel(tags: ['username', 'tenant'])]
final class UsernameAvailabilityForDbalTest
{
    private bool $taken = false;

    #[EventSourcingHandler]
    public function claimed(UsernameClaimedForDbalTest $event): void
    {
        $this->taken = true;
    }

    #[EventSourcingHandler]
    public function released(UsernameReleasedForDbalTest $event): void
    {
        $this->taken = false;
    }

    public function isTaken(): bool
    {
        return $this->taken;
    }
}

/**
 * @internal
 */
final class UsernameRegistrationForDbalTest
{
    #[CommandHandler]
    public function claim(
        ClaimUsernameForDbalTest $command,
        #[Fetch("{'tenant': headers['tenant'], 'username': payload.username}")] UsernameAvailabilityForDbalTest $availability,
        #[Header('tenant')] string $tenant,
    ): array {
        if ($availability->isTaken()) {
            throw new UsernameAlreadyTakenForDbalTest();
        }

        return [new UsernameClaimedForDbalTest($tenant, $command->username)];
    }

    #[CommandHandler]
    public function claimWhileAnotherConnectionCommits(
        ClaimUsernameRacingForDbalTest $command,
        #[Fetch("{'tenant': headers['tenant'], 'username': payload.username}")] UsernameAvailabilityForDbalTest $availability,
        #[Header('tenant')] string $tenant,
        #[Reference] CompetingClaimForDbalTest $competingClaim,
    ): array {
        if ($availability->isTaken()) {
            throw new UsernameAlreadyTakenForDbalTest();
        }

        $competingClaim->commitIfArmed();

        return [new UsernameClaimedForDbalTest($tenant, $command->username)];
    }

    #[CommandHandler]
    public function release(
        ReleaseUsernameForDbalTest $command,
        #[Fetch("{'tenant': headers['tenant'], 'username': payload.username}")] UsernameAvailabilityForDbalTest $availability,
        #[Header('tenant')] string $tenant,
    ): array {
        if (! $availability->isTaken()) {
            throw new UsernameNotTakenForDbalTest();
        }

        return [new UsernameReleasedForDbalTest($tenant, $command->username)];
    }
}

/**
 * @internal
 */
final class UsernameEventsConverterForDbalTest
{
    #[Converter]
    public function fromClaimed(UsernameClaimedForDbalTest $event): array
    {
        return ['tenant' => $event->tenant, 'username' => $event->username];
    }

    #[Converter]
    public function toClaimed(array $event): UsernameClaimedForDbalTest
    {
        return new UsernameClaimedForDbalTest($event['tenant'], $event['username']);
    }

    #[Converter]
    public function fromReleased(UsernameReleasedForDbalTest $event): array
    {
        return ['tenant' => $event->tenant, 'username' => $event->username];
    }

    #[Converter]
    public function toReleased(array $event): UsernameReleasedForDbalTest
    {
        return new UsernameReleasedForDbalTest($event['tenant'], $event['username']);
    }
}
