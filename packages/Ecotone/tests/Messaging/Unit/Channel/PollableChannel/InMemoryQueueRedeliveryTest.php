<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel\PollableChannel;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Headers;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
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
        $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
        $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());

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
        $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));

        [$first, $again] = array_column($orders->deliveries, 'headers');
        self::assertSame($first[MessageHeaders::TRACEPARENT], $again[MessageHeaders::TRACEPARENT] ?? null);
        self::assertSame([MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, $first[MessageHeaders::CONSUMER_ACK_HEADER_LOCATION]], array_values(array_diff(array_keys($first), array_keys($again))));
        self::assertSame([], array_values(array_diff(array_keys($again), array_keys($first))));
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
            [$orders::class],
            [$orders, InMemorySpanRecorder::class => new InMemorySpanRecorder()],
            [SimpleMessageChannelBuilder::createQueueChannel('orders'), ...($tracing ? [TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)] : [])],
            enabledModules: $tracing ? [ModulePackageList::TRACING_PACKAGE] : [],
        );
    }
}
