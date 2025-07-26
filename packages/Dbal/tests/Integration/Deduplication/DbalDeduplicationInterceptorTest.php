<?php

namespace Test\Ecotone\Dbal\Integration\Deduplication;

use Ecotone\Dbal\Deduplication\DeduplicationInterceptor;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\Logger\StubLoggingGateway;
use Ecotone\Messaging\Handler\SymfonyExpressionEvaluationAdapter;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\NativeClock;
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
            new NativeClock(),
            1000,
            1000,
            new StubLoggingGateway(),
            SymfonyExpressionEvaluationAdapter::create(InMemoryReferenceSearchService::createEmpty())
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
            new NativeClock(),
            1000,
            1000,
            new StubLoggingGateway(),
            SymfonyExpressionEvaluationAdapter::create(InMemoryReferenceSearchService::createEmpty())
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

    public function test_deduplicating_with_header_expression()
    {
        $dbalTransactionInterceptor = new DeduplicationInterceptor(
            $this->getConnectionFactory(),
            new NativeClock(),
            1000,
            1000,
            new StubLoggingGateway(),
            SymfonyExpressionEvaluationAdapter::create(InMemoryReferenceSearchService::createEmpty())
        );

        $methodInvocation = StubMethodInvocation::create();
        $deduplicatedAttribute = new Deduplicated(expression: "headers['orderId']");

        // First call with orderId header
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('test')->setHeader('orderId', 'order-123')->build(),
            $deduplicatedAttribute,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        // Second call with same orderId header (should be deduplicated)
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('test')->setHeader('orderId', 'order-123')->build(),
            $deduplicatedAttribute,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        // Third call with different orderId header (should be processed)
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('test')->setHeader('orderId', 'order-456')->build(),
            $deduplicatedAttribute,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(2, $methodInvocation->getCalledTimes());
    }

    public function test_deduplicating_with_payload_expression()
    {
        $dbalTransactionInterceptor = new DeduplicationInterceptor(
            $this->getConnectionFactory(),
            new NativeClock(),
            1000,
            1000,
            new StubLoggingGateway(),
            SymfonyExpressionEvaluationAdapter::create(InMemoryReferenceSearchService::createEmpty())
        );

        $methodInvocation = StubMethodInvocation::create();
        $deduplicatedAttribute = new Deduplicated(expression: 'payload');

        // First call with specific payload
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('unique-payload-1')->build(),
            $deduplicatedAttribute,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        // Second call with same payload (should be deduplicated)
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('unique-payload-1')->build(),
            $deduplicatedAttribute,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        // Third call with different payload (should be processed)
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('unique-payload-2')->build(),
            $deduplicatedAttribute,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(2, $methodInvocation->getCalledTimes());
    }

    public function test_deduplicating_with_complex_expression()
    {
        $dbalTransactionInterceptor = new DeduplicationInterceptor(
            $this->getConnectionFactory(),
            new NativeClock(),
            1000,
            1000,
            new StubLoggingGateway(),
            SymfonyExpressionEvaluationAdapter::create(InMemoryReferenceSearchService::createEmpty())
        );

        $methodInvocation = StubMethodInvocation::create();
        $deduplicatedAttribute = new Deduplicated(expression: "headers['customerId'] ~ '_' ~ payload");

        // First call
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('order-data')->setHeader('customerId', 'customer-123')->build(),
            $deduplicatedAttribute,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        // Second call with same combination (should be deduplicated)
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('order-data')->setHeader('customerId', 'customer-123')->build(),
            $deduplicatedAttribute,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        // Third call with different customer but same payload (should be processed)
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('order-data')->setHeader('customerId', 'customer-456')->build(),
            $deduplicatedAttribute,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(2, $methodInvocation->getCalledTimes());
    }

    public function test_deduplicating_with_tracking_name_isolation()
    {
        $dbalTransactionInterceptor = new DeduplicationInterceptor(
            $this->getConnectionFactory(),
            new NativeClock(),
            1000,
            1000,
            new StubLoggingGateway(),
            SymfonyExpressionEvaluationAdapter::create(InMemoryReferenceSearchService::createEmpty())
        );

        $methodInvocation = StubMethodInvocation::create();
        $deduplicatedAttributeOne = new Deduplicated(expression: "headers['orderId']", trackingName: 'tracking_one');
        $deduplicatedAttributeTwo = new Deduplicated(expression: "headers['orderId']", trackingName: 'tracking_two');

        // First call with tracking_one
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('test')->setHeader('orderId', 'order-123')->build(),
            $deduplicatedAttributeOne,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        // Second call with same orderId but different tracking name (should be processed)
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('test')->setHeader('orderId', 'order-123')->build(),
            $deduplicatedAttributeTwo,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(2, $methodInvocation->getCalledTimes());

        // Third call with same orderId and same tracking name as first (should be deduplicated)
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('test')->setHeader('orderId', 'order-123')->build(),
            $deduplicatedAttributeOne,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(2, $methodInvocation->getCalledTimes());
    }

    public function test_deduplicating_with_tracking_name_overrides_endpoint_id()
    {
        $dbalTransactionInterceptor = new DeduplicationInterceptor(
            $this->getConnectionFactory(),
            new NativeClock(),
            1000,
            1000,
            new StubLoggingGateway(),
            SymfonyExpressionEvaluationAdapter::create(InMemoryReferenceSearchService::createEmpty())
        );

        $methodInvocation = StubMethodInvocation::create();
        $deduplicatedAttribute = new Deduplicated(expression: "headers['orderId']", trackingName: 'custom_tracking');

        // First call with custom tracking name
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('test')->setHeader('orderId', 'order-123')->build(),
            $deduplicatedAttribute,
            null,
            new AsynchronousRunningEndpoint('endpoint1')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());

        // Second call with same orderId and different endpoint but same tracking name (should be deduplicated)
        $dbalTransactionInterceptor->deduplicate(
            $methodInvocation,
            MessageBuilder::withPayload('test')->setHeader('orderId', 'order-123')->build(),
            $deduplicatedAttribute,
            null,
            new AsynchronousRunningEndpoint('endpoint2')
        );

        $this->assertEquals(1, $methodInvocation->getCalledTimes());
    }
}
