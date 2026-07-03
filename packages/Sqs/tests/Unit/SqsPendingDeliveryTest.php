<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Unit;

use Aws\Result;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Sqs\SqsPendingDelivery;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class SqsPendingDeliveryTest extends TestCase
{
    public function test_partially_failed_batch_reports_failed_entries_mapped_to_original_messages(): void
    {
        $deliveredMessage = MessageBuilder::withPayload('delivered order')->build();
        $rejectedMessage = MessageBuilder::withPayload('rejected order')->build();
        $pendingDelivery = new SqsPendingDelivery(
            [fn () => new FulfilledPromise(new Result([
                'Successful' => [['Id' => '0', 'MessageId' => 'aws-message-id']],
                'Failed' => [['Id' => '1', 'Code' => 'InternalError', 'Message' => 'server hiccup', 'SenderFault' => false]],
            ]))],
            [['0' => $deliveredMessage, '1' => $rejectedMessage]],
            'orders',
        );

        $deliveryResult = $pendingDelivery->awaitDelivery();

        $this->assertFalse($deliveryResult->isSuccessful());
        $failedDeliveries = $deliveryResult->getFailedDeliveries();
        $this->assertCount(1, $failedDeliveries);
        $this->assertSame($rejectedMessage, $failedDeliveries[0]->getMessage());
        $this->assertStringContainsString('InternalError', $failedDeliveries[0]->getFailureReason());
        $this->assertSame('orders', $failedDeliveries[0]->getChannelName());
    }

    public function test_rejected_request_reports_all_messages_of_that_request_as_failed(): void
    {
        $firstMessage = MessageBuilder::withPayload('first order')->build();
        $secondMessage = MessageBuilder::withPayload('second order')->build();
        $pendingDelivery = new SqsPendingDelivery(
            [fn () => new RejectedPromise(new RuntimeException('connection refused'))],
            [['0' => $firstMessage, '1' => $secondMessage]],
            'orders',
        );

        $deliveryResult = $pendingDelivery->awaitDelivery();

        $this->assertFalse($deliveryResult->isSuccessful());
        $this->assertCount(2, $deliveryResult->getFailedDeliveries());
        $this->assertStringContainsString('connection refused', $deliveryResult->getFailedDeliveries()[0]->getFailureReason());
    }

    public function test_entries_missing_from_successful_and_failed_lists_are_reported_as_failed(): void
    {
        $confirmedMessage = MessageBuilder::withPayload('confirmed order')->build();
        $unaccountedMessage = MessageBuilder::withPayload('unaccounted order')->build();
        $pendingDelivery = new SqsPendingDelivery(
            [fn () => new FulfilledPromise(new Result([
                'Successful' => [['Id' => '0', 'MessageId' => 'aws-message-id']],
            ]))],
            [['0' => $confirmedMessage, '1' => $unaccountedMessage]],
            'orders',
        );

        $deliveryResult = $pendingDelivery->awaitDelivery();

        $this->assertFalse($deliveryResult->isSuccessful());
        $this->assertCount(1, $deliveryResult->getFailedDeliveries());
        $this->assertSame($unaccountedMessage, $deliveryResult->getFailedDeliveries()[0]->getMessage());
    }

    public function test_fully_confirmed_batch_reports_success_and_is_marked_as_awaited(): void
    {
        $pendingDelivery = new SqsPendingDelivery(
            [fn () => new FulfilledPromise(new Result([
                'Successful' => [['Id' => '0', 'MessageId' => 'first-id'], ['Id' => '1', 'MessageId' => 'second-id']],
            ]))],
            [['0' => MessageBuilder::withPayload('first order')->build(), '1' => MessageBuilder::withPayload('second order')->build()]],
            'orders',
        );

        $this->assertFalse($pendingDelivery->isAwaited());

        $deliveryResult = $pendingDelivery->awaitDelivery();

        $this->assertTrue($deliveryResult->isSuccessful());
        $this->assertTrue($pendingDelivery->isAwaited());
    }

    public function test_send_requests_are_dispatched_lazily_on_await_not_on_creation(): void
    {
        $dispatchedRequests = 0;
        $pendingDelivery = new SqsPendingDelivery(
            [function () use (&$dispatchedRequests) {
                $dispatchedRequests++;

                return new FulfilledPromise(new Result(['Successful' => [['Id' => '0', 'MessageId' => 'aws-message-id']]]));
            }],
            [['0' => MessageBuilder::withPayload('order')->build()]],
            'orders',
        );

        $this->assertSame(0, $dispatchedRequests);

        $pendingDelivery->awaitDelivery();

        $this->assertSame(1, $dispatchedRequests);
    }

    public function test_all_requests_are_dispatched_even_when_earlier_request_is_rejected(): void
    {
        $dispatchedRequests = 0;
        $countingDispatcher = function ($promise) use (&$dispatchedRequests) {
            return function () use ($promise, &$dispatchedRequests) {
                $dispatchedRequests++;

                return $promise;
            };
        };
        $pendingDelivery = new SqsPendingDelivery(
            [
                $countingDispatcher(new RejectedPromise(new RuntimeException('connection refused'))),
                $countingDispatcher(new FulfilledPromise(new Result(['Successful' => [['Id' => '0', 'MessageId' => 'second-id']]]))),
                $countingDispatcher(new FulfilledPromise(new Result(['Successful' => [['Id' => '0', 'MessageId' => 'third-id']]]))),
            ],
            [
                ['0' => MessageBuilder::withPayload('first order')->build()],
                ['0' => MessageBuilder::withPayload('second order')->build()],
                ['0' => MessageBuilder::withPayload('third order')->build()],
            ],
            'orders',
            maxConcurrentRequests: 1,
        );

        $deliveryResult = $pendingDelivery->awaitDelivery();

        $this->assertSame(3, $dispatchedRequests);
        $this->assertCount(1, $deliveryResult->getFailedDeliveries());
        $this->assertSame('first order', $deliveryResult->getFailedDeliveries()[0]->getMessage()->getPayload());
    }
}
