<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel\AsyncPublishing;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingFailedException;
use Ecotone\Messaging\Channel\AsyncPublishing\DeliveryFuture;
use Ecotone\Messaging\Channel\MessageChannelInterceptorAdapter;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Support\MessageBuilder;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\Messaging\Fixture\AsyncPublishing\AsyncOrderSubscriber;
use Test\Ecotone\Messaging\Fixture\AsyncPublishing\FakeTransactionModule;
use Test\Ecotone\Messaging\Fixture\AsyncPublishing\InMemoryAsyncOutboundAdapter;
use Test\Ecotone\Messaging\Fixture\AsyncPublishing\InMemoryAsyncPublisherModule;
use Test\Ecotone\Messaging\Fixture\AsyncPublishing\InMemoryAsyncPublishingChannelBuilder;
use Test\Ecotone\Messaging\Fixture\AsyncPublishing\InMemoryPendingDelivery;
use Test\Ecotone\Messaging\Fixture\AsyncPublishing\OperationsLog;
use Test\Ecotone\Messaging\Fixture\AsyncPublishing\OrderService;

/**
 * licence Apache-2.0
 * @internal
 */
final class AsyncPublishingReliabilityTest extends TestCase
{
    public function test_future_throwing_during_await_keeps_reporting_failure_on_subsequent_resolves(): void
    {
        $throwingDelivery = new InMemoryPendingDelivery(
            MessageBuilder::withPayload('order')->build(),
            throwOnAwait: true,
        );
        $future = DeliveryFuture::forPendingDeliveries([$throwingDelivery]);

        $firstResolveException = null;
        try {
            $future->resolve();
        } catch (AsyncPublishingFailedException $exception) {
            $firstResolveException = $exception;
        }
        $this->assertNotNull($firstResolveException);

        $this->expectException(AsyncPublishingFailedException::class);

        $future->resolve();
    }

    public function test_future_does_not_reawait_deliveries_already_awaited_by_interceptor_scope(): void
    {
        $alreadyAwaitedDelivery = new InMemoryPendingDelivery(MessageBuilder::withPayload('order')->build());
        $alreadyAwaitedDelivery->awaitDelivery();

        DeliveryFuture::forPendingDeliveries([$alreadyAwaitedDelivery])->resolve();

        $this->assertSame(1, $alreadyAwaitedDelivery->awaitCalls());
    }

    public function test_failed_deliveries_routed_to_async_error_channel_are_awaited_before_commit(): void
    {
        $operationsLog = new OperationsLog();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class, AsyncOrderSubscriber::class, FakeTransactionModule::class],
            [new OrderService($operationsLog), new AsyncOrderSubscriber(), OperationsLog::class => $operationsLog],
            ServiceConfiguration::createWithDefaults()->withExtensionObjects([
                \Ecotone\Messaging\Channel\PollableChannel\GlobalPollableChannelConfiguration::createWithDefaults()->withErrorChannel('failure_channel'),
            ]),
            enableAsynchronousProcessing: [
                InMemoryAsyncPublishingChannelBuilder::create('async_orders'),
                InMemoryAsyncPublishingChannelBuilder::create('failure_channel'),
            ],
        );
        $ordersChannel = $ecotoneLite->getMessageChannel('async_orders');
        assert($ordersChannel instanceof MessageChannelInterceptorAdapter);
        $ordersChannel->getInternalMessageChannel()->failDeliveriesWith('broker not available');

        $ecotoneLite->sendCommandWithRoutingKey('order.place', 'espresso');

        $operations = $operationsLog->getOperations();
        $this->assertSame('transaction committed', $operations[count($operations) - 1]);
        $awaitedConfirmationsForFailedBatchAndEachErrorChannelMessage = count(array_filter($operations, fn (string $operation) => $operation === 'delivery confirmations awaited'));
        $this->assertSame(3, $awaitedConfirmationsForFailedBatchAndEachErrorChannelMessage);
    }

    public function test_unresolved_publisher_futures_above_backlog_limit_are_flushed(): void
    {
        $outboundAdapter = new InMemoryAsyncOutboundAdapter();
        $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
            [InMemoryAsyncPublisherModule::class, InMemoryAsyncOutboundAdapter::class],
            [$outboundAdapter],
        );
        $publisher = $ecotoneLite->getGateway(InMemoryAsyncPublisherModule::PUBLISHER_REFERENCE);

        for ($messageNumber = 0; $messageNumber < 1300; $messageNumber++) {
            $publisher->asyncPublish('unresolved order ' . $messageNumber);
        }

        $this->assertGreaterThan(0, $outboundAdapter->awaitedDeliveriesCount());
        $this->assertLessThan(1300, $outboundAdapter->awaitedDeliveriesCount());
    }
}
