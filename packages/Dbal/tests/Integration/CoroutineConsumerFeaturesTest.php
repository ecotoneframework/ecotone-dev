<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\Delayed;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Scheduling\Duration;
use PHPUnit\Framework\Attributes\RequiresMethod;
use RuntimeException;
use Swoole\Coroutine;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineConsumerFeaturesTest extends DbalMessagingTestCase
{
    public function test_messages_with_one_deduplication_key_handled_at_once_by_consumer_coroutines_are_handled_once(): void
    {
        $orders = new class () {
            private array $placed = [];

            #[Deduplicated('orderNumber')]
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderNumber): void
            {
                usleep(50_000);
                $this->placed[] = $orderNumber;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                return $this->placed;
            }
        };
        $ecotone = $this->bootstrapOrders($orders);
        foreach (['order-1', 'order-1', 'order-1', 'order-2'] as $orderNumber) {
            $ecotone->sendCommandWithRouting('order.place', $orderNumber, metadata: ['orderNumber' => $orderNumber]);
        }

        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false)->withCoroutines(4));

        $placed = $ecotone->sendQueryWithRouting('order.placed');
        sort($placed);
        $this->assertSame(['order-1', 'order-2'], $placed);
        $this->assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_delayed_messages_are_handled_at_once_by_consumer_coroutines_only_after_their_delay(): void
    {
        $orders = new class () {
            private array $placed = [];
            private int $placedAtOnce = 0;
            private int $mostPlacedAtOnce = 0;

            #[Delayed(seconds: 60)]
            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $orderNumber): void
            {
                $this->placedAtOnce++;
                $this->mostPlacedAtOnce = max($this->mostPlacedAtOnce, $this->placedAtOnce);
                usleep(50_000);
                $this->placed[] = $orderNumber;
                $this->placedAtOnce--;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                return $this->placed;
            }

            #[QueryHandler('order.mostPlacedAtOnce')]
            public function mostPlacedAtOnce(): int
            {
                return $this->mostPlacedAtOnce;
            }
        };
        $ecotone = $this->bootstrapOrders($orders, new DateTimeImmutable('2026-11-25 12:00:00 UTC'));
        foreach (['order-1', 'order-2', 'order-3'] as $orderNumber) {
            $ecotone->sendCommandWithRouting('order.place', $orderNumber);
        }

        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));
        $placedBeforeTheDelay = $ecotone->sendQueryWithRouting('order.placed');
        $ecotone->advanceTimeBy(Duration::seconds(60));
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages()->withCoroutines(3));

        $placed = $ecotone->sendQueryWithRouting('order.placed');
        sort($placed);
        $this->assertSame([], $placedBeforeTheDelay);
        $this->assertSame(['order-1', 'order-2', 'order-3'], $placed);
        $this->assertSame(3, $ecotone->sendQueryWithRouting('order.mostPlacedAtOnce'));
    }

    private function bootstrapOrders(object $orders, ?DateTimeImmutable $initialTime = null): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            [$orders::class],
            [$orders, DbalConnectionFactory::class => new DbalConnectionFactory(['dsn' => self::mysqlDsn(), 'connection_pool_size' => 10])],
            [
                DbalConfiguration::createWithDefaults(),
                DbalBackedMessageChannelBuilder::create('orders'),
                PollingMetadata::create('orders')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL),
            ],
            [ModulePackageList::DBAL_PACKAGE],
            initialTime: $initialTime,
        );
    }

    private static function mysqlDsn(): string
    {
        foreach ([getenv('DATABASE_DSN'), getenv('SECONDARY_DATABASE_DSN')] as $dsn) {
            if (is_string($dsn) && str_starts_with($dsn, 'mysql')) {
                return $dsn;
            }
        }

        throw new RuntimeException('The coroutine tests need a mysql database in DATABASE_DSN or SECONDARY_DATABASE_DSN.');
    }
}
