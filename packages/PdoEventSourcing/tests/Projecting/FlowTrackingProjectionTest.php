<?php

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
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionExecution;
use Ecotone\Test\LicenceTesting;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;

/**
 * licence Enterprise
 * @internal
 */
final class FlowTrackingProjectionTest extends ProjectingTestCase
{
    protected function tearDown(): void
    {
        ProjectionOutageConnection::$databaseDown = false;
        parent::tearDown();
    }

    public function test_a_database_outage_right_after_an_asynchronous_projection_committed_does_not_fail_its_consumer(): void
    {
        $projection = $this->ticketsProjection();
        $outage = $this->outageRightAfterTheConsumerWork();
        $ecotone = $this->bootstrapEcotone([$projection, $outage]);
        $tracingId = Uuid::uuid4()->toString();
        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'User1', 'alert'), metadata: [MessageHeaders::TRACING_ID => $tracingId]);

        $outage->armed = true;
        $this->drain($ecotone);
        $outage->armed = false;
        ProjectionOutageConnection::$databaseDown = false;

        self::assertSame(1, $projection->projected);
        self::assertTrue($ecotone->getGateway(TracingGateway::class)->isCompletedFor($tracingId, 'flow_tracked_tickets'));
        self::assertTrue($ecotone->getGateway(TracingGateway::class)->isCompletedFor($tracingId));
    }

    public function test_an_asynchronous_projection_spread_over_several_batches_completes_the_flow_once_it_caught_up(): void
    {
        $projection = $this->ticketsProjection();
        $ecotone = $this->bootstrapEcotone([$projection]);
        $ecotone->sendCommand(new RegisterTicket('ticket-1', 'User1', 'alert'));
        $ecotone->sendCommand(new RegisterTicket('ticket-2', 'User2', 'alert'));
        $tracingId = Uuid::uuid4()->toString();
        $ecotone->sendCommand(new RegisterTicket('ticket-3', 'User3', 'alert'), metadata: [MessageHeaders::TRACING_ID => $tracingId]);

        $this->drain($ecotone);

        self::assertSame(3, $projection->projected);
        self::assertTrue($ecotone->getGateway(TracingGateway::class)->isCompletedFor($tracingId));
    }

    private function drain(FlowTestSupport $ecotone): void
    {
        $ecotone->run('async_projection', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withExecutionTimeLimitInMilliseconds(10000));
    }

    private function ticketsProjection(): object
    {
        return new #[Projection('flow_tracked_tickets'), Asynchronous('async_projection'), ProjectionExecution(eventLoadingBatchSize: 1), FromAggregateStream(Ticket::class)] class () {
            public int $projected = 0;

            #[EventHandler]
            public function whenRegistered(TicketWasRegistered $event): void
            {
                $this->projected++;
            }
        };
    }

    private function outageRightAfterTheConsumerWork(): object
    {
        return new class () {
            public bool $armed = false;

            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 2, pointcut: 'Ecotone\\Api\\Attribute\\AsynchronousRunningEndpoint')]
            public function goDownAfterTheWork(MethodInvocation $methodInvocation): mixed
            {
                $result = $methodInvocation->proceed();
                if ($this->armed) {
                    ProjectionOutageConnection::$databaseDown = true;
                }

                return $result;
            }
        };
    }

    /**
     * @param object[] $services
     */
    private function bootstrapEcotone(array $services): FlowTestSupport
    {
        $connectionFactory = new DbalConnectionFactory([
            'dsn' => getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@127.0.0.1:5432/ecotone',
            'connection' => ['wrapperClass' => ProjectionOutageConnection::class],
        ]);

        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: [...array_map(fn (object $service): string => $service::class, $services), Ticket::class, TicketEventConverter::class],
            containerOrAvailableServices: [...$services, new TicketEventConverter(), DbalConnectionFactory::class => $connectionFactory],
            extensionObjects: [
                FlowTrackingConfiguration::createWithDefaults(),
                SimpleMessageChannelBuilder::createQueueChannel('async_projection'),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::EVENT_SOURCING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->deleteProjection('flow_tracked_tickets')->initializeProjection('flow_tracked_tickets');

        return $ecotone;
    }
}

final class ProjectionOutageConnection extends Connection
{
    public static bool $databaseDown = false;

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        self::failWhenDown();

        return parent::executeQuery($sql, $params, $types, $qcp);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        self::failWhenDown();

        return parent::executeStatement($sql, $params, $types);
    }

    private static function failWhenDown(): void
    {
        if (self::$databaseDown) {
            throw new RuntimeException('The database is down');
        }
    }
}
