<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Test\Flow\Snapshot\FlowExecution;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class RedeliveredFlowExecutionRecordingTest extends DbalMessagingTestCase
{
    public function test_a_message_the_dbal_channel_delivers_again_after_a_failure_is_each_time_a_sub_flow_of_the_send_that_put_it_on_the_channel(): void
    {
        $orders = new class () {
            public int $attempts = 0;

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
                if (++$this->attempts === 1) {
                    throw new RuntimeException('the first delivery fails');
                }
            }
        };
        $recorder = new InMemorySpanRecorder();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN')), InMemorySpanRecorder::class => $recorder],
            [DbalBackedMessageChannelBuilder::create('orders')->withReceiveTimeout(100), InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false), TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withExecutionRecording()],
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::TRACING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $ecotone->sendCommandWithRouting('order.place', 'order-1');
        $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false));
        $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        $processes = FlowExecution::of($recorder->recordedSpans())->processes;

        self::assertSame(2, $orders->attempts);
        self::assertCount(3, $processes);
        self::assertSame(1, $processes[1]['sentBy']['process']);
        self::assertSame($processes[1]['sentBy'], $processes[2]['sentBy'] ?? null);
    }
}
