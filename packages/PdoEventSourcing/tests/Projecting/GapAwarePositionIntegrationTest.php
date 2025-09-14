<?php
/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\Projecting\StreamSource\GapAwarePosition;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Scheduling\StubUTCClock;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\ProjectingManager;
use Ecotone\Projecting\ProjectionRegistry;
use Enqueue\Dbal\DbalConnectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Test\Ecotone\EventSourcing\Projecting\Fixture\DbalTicketProjection;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\CreateTicketCommand;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketEventConverter;

class GapAwarePositionIntegrationTest extends TestCase
{
    private static DbalConnectionFactory $connectionFactory;
    private static StubUTCClock $clock;
    private static FlowTestSupport $ecotone;
    private static DbalTicketProjection $projection;
    private static EventStore $eventStore;
    private static ProjectingManager $projectionManager;

    protected function setUp(): void
    {
        self::$connectionFactory = self::createConnectionFactory();
        self::$clock = new StubUTCClock();
        self::$ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
            classesToResolve: [DbalTicketProjection::class],
            containerOrAvailableServices: [
                self::$projection = new DbalTicketProjection(self::$connectionFactory->establishConnection()),
                new TicketEventConverter(),
                DbalConnectionFactory::class => self::$connectionFactory,
                ClockInterface::class => self::$clock
            ],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withEnvironment('prod')
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::EVENT_SOURCING_PACKAGE]))
                ->withNamespaces([
                    'Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket',
                ]),
            pathToRootCatalog: __DIR__ . '/../../',
            runForProductionEventStore: true
        );

        self::$eventStore = self::$ecotone->getGateway(EventStore::class);
        self::$projectionManager = self::$ecotone->getGateway(ProjectionRegistry::class)->get(DbalTicketProjection::NAME);
        if (self::$eventStore->hasStream(Ticket::STREAM_NAME)) {
            self::$eventStore->delete(Ticket::STREAM_NAME);
        }
        self::$eventStore->create(Ticket::STREAM_NAME);
        self::$projectionManager->delete();
    }

    public function test_gaps_are_added_to_position(): void
    {
        for($i = 1; $i <= 6; $i++) {
            $this->insertGaps(Ticket::STREAM_NAME);
            self::$ecotone->sendCommand(new CreateTicketCommand('ticket-' . $i));
        }

        self::$ecotone->triggerProjection(DbalTicketProjection::NAME);

        self::assertSame(6, self::$projection->getTicketsCount());
        $position = GapAwarePosition::fromString(self::$projectionManager->loadState()->lastPosition);
        self::assertSame(12, $position->getPosition());
        self::assertSame([1,3,5,7,9,11], $position->getGaps());
    }

    private function insertGaps(string $stream, int $count = 1): void
    {
        self::$connectionFactory->establishConnection()->beginTransaction();
        for ($i = 0; $i < $count; $i++) {
            self::$eventStore->appendTo($stream, [
                Event::createWithType('order-gap', [], ['_aggregate_type' => 'an-aggregate-type', '_aggregate_id' => uniqid('order-gap-'), '_aggregate_version' => 0]),
            ]);
        }
        self::$connectionFactory->establishConnection()->rollBack();
    }

    protected function createConnectionFactory(): DbalConnectionFactory
    {
        $dsn = getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone';
        if (! $dsn) {
            throw new InvalidArgumentException('Missing env `DATABASE_DSN` pointing to test database');
        }
        return new DbalConnectionFactory($dsn);
    }
}
