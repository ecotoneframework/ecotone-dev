<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ApplicationConnectionLifetimeTest extends DbalMessagingTestCase
{
    public function test_a_discarded_application_releases_its_database_connection(): void
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite has no server side connections to count');
        }

        $connectionsBefore = $this->openConnections();

        for ($application = 1; $application <= 3; $application++) {
            $ecotoneLite = $this->bootstrapApplicationOn(new DbalConnectionFactory(getenv('DATABASE_DSN')));

            $ecotoneLite->sendCommandWithRouting('order.place', "order-{$application}");
            $ecotoneLite->run('orders');
            $this->assertSame(["order-{$application}"], $ecotoneLite->sendQueryWithRouting('order.placed'));

            $ecotoneLite = null;
            gc_collect_cycles();
        }

        $this->assertSame($connectionsBefore, $this->openConnections());
    }

    public function test_an_application_shares_one_database_connection_between_its_channels_and_transactions(): void
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite has no server side connections to count');
        }

        $connectionsBefore = $this->openConnections();

        $ecotoneLite = $this->bootstrapApplicationOn(new DbalConnectionFactory(getenv('DATABASE_DSN')));
        $ecotoneLite->sendCommandWithRouting('order.place', 'order-1');
        $ecotoneLite->sendCommandWithRouting('order.place', 'order-2');
        $ecotoneLite->run('orders');
        $ecotoneLite->run('shipments');

        $this->assertSame(['order-1', 'order-2'], $ecotoneLite->sendQueryWithRouting('order.shipped'));
        $this->assertSame($connectionsBefore + 1, $this->openConnections());
    }

    private function bootstrapApplicationOn(DbalConnectionFactory $connectionFactory): FlowTestSupport
    {
        $handler = new class () {
            private array $placed = [];
            private array $shipped = [];

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId, CommandBus $commandBus): void
            {
                $this->placed[] = $orderId;
                $commandBus->sendWithRouting('order.ship', $orderId);
            }

            #[Asynchronous('shipments')]
            #[CommandHandler('order.ship', endpointId: 'order.ship.endpoint')]
            public function ship(string $orderId): void
            {
                $this->shipped[] = $orderId;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                return $this->placed;
            }

            #[QueryHandler('order.shipped')]
            public function shipped(): array
            {
                return $this->shipped;
            }
        };

        return $this->bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => $connectionFactory],
            [
                DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true)->withTransactionOnAsynchronousEndpoints(true),
                DbalBackedMessageChannelBuilder::create('orders')->withReceiveTimeout(1),
                DbalBackedMessageChannelBuilder::create('shipments')->withReceiveTimeout(1),
            ],
            [ModulePackageList::DBAL_PACKAGE],
        );
    }

    private function openConnections(): int
    {
        $connection = $this->getConnection();

        return (int) $connection->fetchOne(
            $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform
                ? 'SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()'
                : 'SELECT count(*) FROM information_schema.PROCESSLIST WHERE db = DATABASE()',
        );
    }
}
