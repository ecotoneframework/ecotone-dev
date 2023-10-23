<?php

namespace Test\Ecotone\Dbal\Integration\Deduplication;

use Ecotone\Dbal\Deduplication\DeduplicationInterceptor;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\EpochBasedClock;
use Ecotone\Messaging\Scheduling\StubUTCClock;
use Ecotone\Messaging\Support\MessageBuilder;
use Psr\Log\NullLogger;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\StubMethodInvocation;

/**
 * @internal
 */
class DbalDeduplicationInterceptorTest extends DbalMessagingTestCase
{
    public function test_not_deduplicating_for_different_endpoints()
    {
        $dbalTransactionInterceptor = new DeduplicationInterceptor(
            $this->getConnectionFactory(),
            new EpochBasedClock(),
            1000,
            new NullLogger()
        );

        $methodInvocation = StubMethodInvocation::create();

        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload([])->setMultipleHeaders([MessageHeaders::MESSAGE_ID => 1])->build(),
            null,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload([])->setMultipleHeaders([MessageHeaders::MESSAGE_ID => 1])->build(),
            null,
            null,
            new AsynchronousRunningEndpoint('endpoint2')
        );

        $this->assertEquals(2, $methodInvocation->getCalledTimes());
    }

    public function test_not_handling_same_message_twice()
    {
        $dbalTransactionInterceptor = new DeduplicationInterceptor(
            $this->getConnectionFactory(),
            new EpochBasedClock(),
            1000,
            new NullLogger()
        );

        $methodInvocation = StubMethodInvocation::create();

        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload([])->setMultipleHeaders([MessageHeaders::MESSAGE_ID => 1])->build(),
            null,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload([])->setMultipleHeaders([MessageHeaders::MESSAGE_ID => 1])->build(),
            null,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());
    }

    public function test_handling_message_with_same_id_when_it_was_removed_by_time_limit()
    {
        $clock = StubUTCClock::createWithCurrentTime('2017-01-01 00:00:00');
        $dbalTransactionInterceptor = new DeduplicationInterceptor(
            $this->getConnectionFactory(),
            $clock,
            1000,
            new NullLogger()
        );

        $methodInvocation = StubMethodInvocation::create();

        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload([])->setMultipleHeaders([MessageHeaders::MESSAGE_ID => 1])->build(),
            null,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        $clock->sleep(1);

        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload([])->setMultipleHeaders([MessageHeaders::MESSAGE_ID => 1])->build(),
            null,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(2, $methodInvocation->getCalledTimes());
    }
}
