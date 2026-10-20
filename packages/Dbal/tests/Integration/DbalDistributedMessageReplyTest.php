<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

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
final class DbalDistributedMessageReplyTest extends DbalMessagingTestCase
{
    public function test_the_result_of_a_distributed_message_reaches_the_reply_routing_key_over_dbal_channels(): void
    {
        $tickets = new class () {
            #[Distributed]
            #[InternalHandler('ticket.create')]
            public function create(string $description): DbalTicketWasCreated
            {
                return new DbalTicketWasCreated('ticket-1', $description);
            }
        };
        $users = new class () {
            public array $replies = [];

            #[Distributed]
            #[InternalHandler('ticket.created')]
            public function created(DbalTicketWasCreated $ticket, #[Header('requestedBy')] string $requestedBy): void
            {
                $this->replies[] = [$ticket, $requestedBy];
            }
        };
        $userService = $this->service('user_service', $users, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'ticket_channel'), $this->getConnectionFactory());
        $ticketService = $this->service('ticket_service', $tickets, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'user_service', channelName: 'user_channel'), $this->getConnectionFactory());

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', metadata: ['requestedBy' => 'johny'], replyRoutingKey: 'ticket.created');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertEquals([[new DbalTicketWasCreated('ticket-1', 'Printer is broken'), 'johny']], $users->replies);
    }

    public function test_a_reply_that_cannot_be_sent_rolls_back_the_handler_and_the_retried_request_replies_once(): void
    {
        $connectionFactory = new DbalConnectionFactory([
            'dsn' => getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone',
            'connection' => ['wrapperClass' => ReplyOutageConnection::class],
        ]);
        $connection = $connectionFactory->createContext()->getDbalConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS reply_tickets');
        $connection->executeStatement('CREATE TABLE reply_tickets (description VARCHAR(255))');
        $tickets = new class ($connectionFactory) {
            private int $attempts = 0;

            public function __construct(private DbalConnectionFactory $connectionFactory)
            {
            }

            #[Distributed]
            #[InternalHandler('ticket.create')]
            public function create(string $description): string
            {
                $this->connectionFactory->createContext()->getDbalConnection()->insert('reply_tickets', ['description' => $description]);
                ReplyOutageConnection::$downUntilRollback = ++$this->attempts === 1;

                return 'ticket for: ' . $description;
            }
        };
        $users = new class () {
            public array $replies = [];

            #[Distributed]
            #[InternalHandler('ticket.created')]
            public function created(string $ticket): void
            {
                $this->replies[] = $ticket;
            }
        };
        $userService = $this->service('user_service', $users, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'ticket_channel'), $connectionFactory);
        $ticketService = $this->service('ticket_service', $tickets, DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: 'user_service', channelName: 'user_channel'), $connectionFactory);

        $userService->getDistributedBus()->sendMessage('ticket_service', 'ticket.create', 'Printer is broken', replyRoutingKey: 'ticket.created');
        $ticketService->run('ticket_channel', ExecutionPollingMetadata::createWithTestingSetup());
        $userService->run('user_channel', ExecutionPollingMetadata::createWithTestingSetup());

        self::assertSame(['ticket for: Printer is broken'], $users->replies);
        self::assertSame(['Printer is broken'], $connection->fetchFirstColumn('SELECT description FROM reply_tickets'));
    }

    private function service(string $serviceName, object $handlers, DistributedServiceMap $serviceMap, DbalConnectionFactory $connectionFactory): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            classesToResolve: [$handlers::class],
            containerOrAvailableServices: [$handlers, DbalConnectionFactory::class => $connectionFactory],
            extensionObjects: [
                DbalBackedMessageChannelBuilder::create('ticket_channel'),
                DbalBackedMessageChannelBuilder::create('user_channel'),
                $serviceMap,
                ServiceConfiguration::createWithDefaults()->withServiceName($serviceName),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}

final class DbalTicketWasCreated
{
    public function __construct(public readonly string $ticketId, public readonly string $description)
    {
    }
}

final class ReplyOutageConnection extends Connection
{
    public static bool $downUntilRollback = false;

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

    public function rollBack(): void
    {
        self::$downUntilRollback = false;

        parent::rollBack();
    }

    private static function failWhenDown(): void
    {
        if (self::$downUntilRollback) {
            throw new RuntimeException('The database is down');
        }
    }
}
