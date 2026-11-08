<?php

declare(strict_types=1);

namespace Test\Ecotone\Redis\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Api\Redis\RedisConnectionFactory;
use Redis;
use Test\Ecotone\Redis\ConnectionTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class ApplicationConnectionLifetimeTest extends ConnectionTestCase
{
    public function test_a_discarded_application_releases_its_redis_connection(): void
    {
        gc_collect_cycles();
        $connectionsBefore = $this->openConnections();

        for ($application = 1; $application <= 3; $application++) {
            $ecotoneLite = $this->bootstrapApplication();

            $ecotoneLite->sendCommandWithRouting('order.place', "order-{$application}");
            $ecotoneLite->run('orders');
            $this->assertSame(["order-{$application}"], $ecotoneLite->sendQueryWithRouting('order.placed'));

            $ecotoneLite = null;
            gc_collect_cycles();
        }

        $this->assertOpenConnectionsSettleAt($connectionsBefore);
    }

    private function bootstrapApplication(): FlowTestSupport
    {
        $handler = new class () {
            private array $placed = [];

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderId): void
            {
                $this->placed[] = $orderId;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                return $this->placed;
            }
        };

        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class],
            containerOrAvailableServices: [$handler, RedisConnectionFactory::class => self::getConnection()],
            extensionObjects: [RedisBackedMessageChannelBuilder::create('orders')],
            enabledModules: [ModulePackageList::REDIS_PACKAGE],
        );
    }

    private function assertOpenConnectionsSettleAt(int $expected): void
    {
        $deadline = microtime(true) + 5;
        while (($openConnections = $this->openConnections()) !== $expected && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertSame($expected, $openConnections);
    }

    private function openConnections(): int
    {
        $dsn = parse_url(getenv('REDIS_DSN') ?: 'redis://localhost:6379');
        $redis = new Redis();
        $redis->connect($dsn['host'], $dsn['port'] ?? 6379);
        $clients = count($redis->client('list')) - 1;
        $redis->close();

        return $clients;
    }
}
