<?php

namespace Test\Ecotone\Dbal\Integration\Deduplication;

use Ecotone\Dbal\Deduplication\DeduplicationInterceptor;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Handler\Logger\StubLoggingGateway;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\EpochBasedClock;
use Ecotone\Messaging\Support\MessageBuilder;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Test\Ecotone\Dbal\Fixture\StubMethodInvocation;

/**
 * @internal
 */
/**
 * licence Apache-2.0
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
            1000,
            new StubLoggingGateway()
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
            1000,
            new StubLoggingGateway()
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
}
