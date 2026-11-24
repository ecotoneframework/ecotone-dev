<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel\PollableChannel;

use DateTimeImmutable;
use Ecotone\Api\Attribute\AddHeader;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\ChannelInterceptor;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Delayed;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\Attribute\RemoveHeader;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class InMemoryQueueRedeliveryTest extends TestCase
{
    public function test_a_message_redelivered_after_a_failure_keeps_its_payload_and_headers_comes_after_the_queued_messages_and_is_acknowledged_once_handled(): void
    {
        $orders = $this->failingOnceFor('order-a');
        $ecotone = $this->application($orders, tracing: false);

        $ecotone->sendCommandWithRouting('order.place', 'order-a', metadata: ['customer' => 'alice']);
        $ecotone->sendCommandWithRouting('order.place', 'order-b', metadata: ['customer' => 'bob']);
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(
            [['order-a', 'alice'], ['order-b', 'bob'], ['order-a', 'alice']],
            array_map(fn (array $delivery): array => [$delivery['payload'], $delivery['headers']['customer']], $orders->deliveries),
        );
    }

    public function test_a_message_redelivered_after_a_failure_carries_the_headers_it_was_first_delivered_with_except_its_acknowledgement(): void
    {
        $orders = $this->failingOnceFor('order-a');
        $ecotone = $this->application($orders, tracing: true);

        $ecotone->sendCommandWithRouting('order.place', 'order-a');
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        [$first, $again] = array_column($orders->deliveries, 'headers');
        self::assertSame($first[MessageHeaders::TRACEPARENT], $again[MessageHeaders::TRACEPARENT] ?? null);
        self::assertSame([MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, $first[MessageHeaders::CONSUMER_ACK_HEADER_LOCATION]], array_values(array_diff(array_keys($first), array_keys($again))));
        self::assertSame([], array_values(array_diff(array_keys($again), array_keys($first))));
    }

    public function test_a_message_redelivered_after_a_failure_keeps_a_header_added_before_it_was_put_on_the_channel(): void
    {
        $orders = new class () {
            public array $tokens = [];

            #[Asynchronous('orders')]
            #[AddHeader('token', 'abc')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order, #[Header('token')] ?string $token = null): void
            {
                $this->tokens[] = $token;
                if (count($this->tokens) === 1) {
                    throw new RuntimeException('the first delivery fails');
                }
            }
        };
        $ecotone = $this->application($orders, tracing: false);

        $ecotone->sendCommandWithRouting('order.place', 'order-a');
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        self::assertSame(['abc', 'abc'], $orders->tokens);
    }

    public function test_a_message_redelivered_after_a_failure_still_lacks_a_header_removed_before_it_was_put_on_the_channel(): void
    {
        $orders = new class () {
            public array $customers = [];

            #[Asynchronous('orders')]
            #[RemoveHeader('customer')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order, #[Header('customer')] ?string $customer = null): void
            {
                $this->customers[] = $customer;
                if (count($this->customers) === 1) {
                    throw new RuntimeException('the first delivery fails');
                }
            }
        };
        $ecotone = $this->application($orders, tracing: false);

        $ecotone->sendCommandWithRouting('order.place', 'order-a', metadata: ['customer' => 'alice']);
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        self::assertSame([null, null], $orders->customers);
    }

    public function test_a_delayed_message_redelivered_after_a_failure_is_not_delayed_a_second_time(): void
    {
        $orders = new class () {
            public int $deliveries = 0;

            #[Asynchronous('orders')]
            #[Delayed(5000)]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
                if (++$this->deliveries === 1) {
                    throw new RuntimeException('the first delivery fails');
                }
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$orders], [SimpleMessageChannelBuilder::createQueueChannel('orders')], enabledModules: [], initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'));

        $ecotone->sendCommandWithRouting('order.place', 'order-a');
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        $beforeTheDelay = $orders->deliveries;
        $ecotone->advanceTimeBy(Duration::seconds(5));
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        self::assertSame([0, 2], [$beforeTheDelay, $orders->deliveries]);
    }

    public function test_a_message_redelivered_after_a_failure_keeps_the_headers_a_negative_precedence_channel_interceptor_added_when_it_was_put_on_the_channel(): void
    {
        $orders = new class () {
            public array $stamps = [];

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order, #[Header('stamp')] ?string $stamp = null): void
            {
                $this->stamps[] = $stamp;
                if (count($this->stamps) === 1) {
                    throw new RuntimeException('the first delivery fails');
                }
            }

            #[ChannelInterceptor('orders', changeHeaders: true, precedence: -100000)]
            public function stamp(): array
            {
                return ['stamp' => 'put on orders'];
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting([], [$orders], [SimpleMessageChannelBuilder::createQueueChannel('orders')], enabledModules: [], licenceKey: LicenceTesting::VALID_LICENCE, initialTime: new DateTimeImmutable('2026-11-22 10:00:00'));

        $ecotone->sendCommandWithRouting('order.place', 'order-a');
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        self::assertSame(['put on orders', 'put on orders'], $orders->stamps);
    }

    private function failingOnceFor(string $failingOrder): object
    {
        return new class ($failingOrder) {
            public array $deliveries = [];
            private bool $failed = false;

            public function __construct(private string $failingOrder)
            {
            }

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order, #[Headers] array $headers): void
            {
                $this->deliveries[] = ['payload' => $order, 'headers' => $headers];
                if ($order === $this->failingOrder && ! $this->failed) {
                    $this->failed = true;
                    throw new RuntimeException('the first delivery fails');
                }
            }
        };
    }

    private function application(object $orders, bool $tracing): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [],
            [$orders, InMemorySpanRecorder::class => new InMemorySpanRecorder()],
            [SimpleMessageChannelBuilder::createQueueChannel('orders'), ...($tracing ? [TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)] : [])],
            enabledModules: $tracing ? [ModulePackageList::TRACING_PACKAGE] : [],
            initialTime: new DateTimeImmutable('2026-11-22 10:00:00'),
        );
    }
}
