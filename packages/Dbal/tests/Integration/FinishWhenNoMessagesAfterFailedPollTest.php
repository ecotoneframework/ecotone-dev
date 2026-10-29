<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\RetryTemplateBuilder;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
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
