<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\OnConsumerStop;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Lite\EcotoneLite;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class FinishWhenNoMessagesAfterFailedPollTest extends DbalMessagingTestCase
{
    public function test_a_poll_that_lost_its_connection_does_not_end_a_finish_when_no_messages_run(): void
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite has no server to terminate a connection');
        }
        $connectionFactory = new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone');
        $orders = new class () {
            private array $placed = [];

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
                $this->placed[] = $order;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                return $this->placed;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, DbalConnectionFactory::class => $connectionFactory],
            [
                DbalBackedMessageChannelBuilder::create('orders')->withReceiveTimeout(100),
                ServiceConfiguration::createWithDefaults()->withConnectionRetryTemplate(RetryTemplateBuilder::fixedBackOff(10)->maxRetries(3)),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
        );

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $this->terminateServerSideOf($connectionFactory);
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        $this->assertSame(['milk'], $ecotone->sendQueryWithRouting('order.placed'));
    }

    public function test_a_run_whose_connection_never_comes_back_throws_after_the_connection_retries(): void
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite has no server to lose the connection to');
        }
        $unreachable = new DbalConnectionFactory(preg_replace('/:\d+\//', ':1/', getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone'));
        $orders = new class () {
            private array $log = [];

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
            }

            #[OnConsumerStop]
            public function stopped(string $endpointId, #[Reference] EcotoneClockInterface $clock): void
            {
                $this->log[] = 'stopped at ' . $clock->now()->format('H:i:s.v');
            }

            #[QueryHandler('consumer.log')]
            public function log(): array
            {
                return $this->log;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, DbalConnectionFactory::class => $unreachable],
            [
                DbalBackedMessageChannelBuilder::create('orders')->withReceiveTimeout(100),
                ServiceConfiguration::createWithDefaults()->withConnectionRetryTemplate(RetryTemplateBuilder::fixedBackOff(1000)->maxRetries(2)),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
            testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-01-01 00:00:00'))->withDatabaseTablesSetUpOnBootstrap(false),
        );

        try {
            $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
            $this->fail('The run ended quietly on a channel whose connection never came back');
        } catch (ConnectionException) {
        }

        $this->assertSame(['stopped at 00:00:02.000'], $ecotone->sendQueryWithRouting('consumer.log'));
    }

    private function terminateServerSideOf(DbalConnectionFactory $connectionFactory): void
    {
        $owned = $connectionFactory->createContext()->getDbalConnection();
        $administrator = $this->getConnection();

        if ($administrator->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $administrator->executeStatement('SELECT pg_terminate_backend(' . (int) $owned->fetchOne('SELECT pg_backend_pid()') . ')');

            return;
        }

        $administrator->executeStatement('KILL ' . (int) $owned->fetchOne('SELECT CONNECTION_ID()'));
    }
}
