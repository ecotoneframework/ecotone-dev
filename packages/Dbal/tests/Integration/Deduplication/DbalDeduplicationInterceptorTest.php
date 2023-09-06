<?php

namespace Test\Ecotone\Dbal\Integration\Deduplication;

use Ecotone\Dbal\Deduplication\DeduplicationInterceptor;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\EpochBasedClock;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\StubMethodInvocation;

/**
 * @internal
 */
class DbalDeduplicationInterceptorTest extends DbalMessagingTestCase
{
    public function test_not_deduplicating_for_different_endpoints()
    {
        $dbalTransactionInterceptor = new DeduplicationInterceptor(DbalConnectionFactory::class, new EpochBasedClock(), 1000);

        $methodInvocation = StubMethodInvocation::create();

        $dbalTransactionInterceptor->deduplicate($methodInvocation, MessageBuilder::withPayload([])->setMultipleHeaders([
            MessageHeaders::MESSAGE_ID => 1,
        ])->build(), $this->getReferenceSearchServiceWithConnection(), null, null, new AsynchronousRunningEndpoint('endpoint1'));

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        $dbalTransactionInterceptor->deduplicate($methodInvocation, MessageBuilder::withPayload([])->setMultipleHeaders([
            MessageHeaders::MESSAGE_ID => 1,
        ])->build(), $this->getReferenceSearchServiceWithConnection(), null, null, new AsynchronousRunningEndpoint('endpoint2'));

        $this->assertEquals(2, $methodInvocation->getCalledTimes());
    }

    public function test_not_handling_same_message_twice()
    {
        $dbalTransactionInterceptor = new DeduplicationInterceptor(DbalConnectionFactory::class, new EpochBasedClock(), 1000);

        $methodInvocation = StubMethodInvocation::create();

        $dbalTransactionInterceptor->deduplicate($methodInvocation, MessageBuilder::withPayload([])->setMultipleHeaders([
            MessageHeaders::MESSAGE_ID => 1,
        ])->build(), $this->getReferenceSearchServiceWithConnection(), null, null, new AsynchronousRunningEndpoint('endpoint1'));

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        $dbalTransactionInterceptor->deduplicate($methodInvocation, MessageBuilder::withPayload([])->setMultipleHeaders([
            MessageHeaders::MESSAGE_ID => 1,
        ])->build(), $this->getReferenceSearchServiceWithConnection(), null, null, new AsynchronousRunningEndpoint('endpoint1'));

        $this->assertEquals(1, $methodInvocation->getCalledTimes());
    }

    public function test_handling_message_with_same_id_when_it_was_removed_by_time_limit()
    {
        $dbalTransactionInterceptor = new DeduplicationInterceptor(DbalConnectionFactory::class, new EpochBasedClock(), 1);

        $methodInvocation = StubMethodInvocation::create();

        $dbalTransactionInterceptor->deduplicate($methodInvocation, MessageBuilder::withPayload([])->setMultipleHeaders([
            MessageHeaders::MESSAGE_ID => 1,
        ])->build(), $this->getReferenceSearchServiceWithConnection(), null, null, new AsynchronousRunningEndpoint('endpoint1'));

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        usleep(2000);
        $dbalTransactionInterceptor->deduplicate($methodInvocation, MessageBuilder::withPayload([])->setMultipleHeaders([
            MessageHeaders::MESSAGE_ID => 1,
        ])->build(), $this->getReferenceSearchServiceWithConnection(), null, null, new AsynchronousRunningEndpoint('endpoint1'));

        $this->assertEquals(2, $methodInvocation->getCalledTimes());
    }
}
