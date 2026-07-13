<?php

declare(strict_types=1);

namespace Test\Ecotone\Sqs\Unit;

use Ecotone\Sqs\SqsRequestDispatchPool;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * licence Apache-2.0
 * @internal
 */
final class SqsRequestDispatchPoolTest extends TestCase
{
    public function test_requests_within_concurrency_budget_are_dispatched_at_registration(): void
    {
        $pool = new SqsRequestDispatchPool(maxConcurrentRequests: 2);
        $dispatchedRequests = 0;
        $dispatcher = function () use (&$dispatchedRequests) {
            $dispatchedRequests++;

            return new Promise();
        };

        $pool->dispatch($dispatcher);
        $pool->dispatch($dispatcher);
        $pool->dispatch($dispatcher);

        $this->assertSame(2, $dispatchedRequests);
    }

    public function test_queued_request_is_dispatched_when_earlier_request_settles(): void
    {
        $pool = new SqsRequestDispatchPool(maxConcurrentRequests: 1);
        $firstUnderlying = new Promise();
        $dispatchedSecond = false;

        $pool->dispatch(fn () => $firstUnderlying);
        $pool->dispatch(function () use (&$dispatchedSecond) {
            $dispatchedSecond = true;

            return new Promise();
        });

        $this->assertFalse($dispatchedSecond);

        $firstUnderlying->resolve('confirmed');
        \GuzzleHttp\Promise\Utils::queue()->run();

        $this->assertTrue($dispatchedSecond);
    }

    public function test_proxy_resolves_with_underlying_value_and_rejects_with_underlying_reason(): void
    {
        $pool = new SqsRequestDispatchPool(maxConcurrentRequests: 2);
        $fulfilledUnderlying = new Promise();
        $rejectedUnderlying = new Promise();

        $fulfilledProxy = $pool->dispatch(fn () => $fulfilledUnderlying);
        $rejectedProxy = $pool->dispatch(fn () => $rejectedUnderlying);

        $fulfilledUnderlying->resolve('confirmed');
        $rejectedUnderlying->reject(new RuntimeException('connection refused'));
        \GuzzleHttp\Promise\Utils::queue()->run();

        $this->assertSame(PromiseInterface::FULFILLED, $fulfilledProxy->getState());
        $this->assertSame('confirmed', $fulfilledProxy->wait());
        $this->assertSame(PromiseInterface::REJECTED, $rejectedProxy->getState());
    }

    public function test_synchronously_throwing_dispatcher_rejects_only_its_proxy_and_frees_the_slot(): void
    {
        $pool = new SqsRequestDispatchPool(maxConcurrentRequests: 1);
        $dispatchedSecond = false;

        $throwingProxy = $pool->dispatch(fn () => throw new RuntimeException('curl init failed'));
        $pool->dispatch(function () use (&$dispatchedSecond) {
            $dispatchedSecond = true;

            return new Promise();
        });

        $this->assertSame(PromiseInterface::REJECTED, $throwingProxy->getState());
        $this->assertTrue($dispatchedSecond);
    }

    public function test_waiting_on_queued_proxy_drives_earlier_requests_until_slot_frees(): void
    {
        $pool = new SqsRequestDispatchPool(maxConcurrentRequests: 1);
        $firstUnderlying = new Promise(function () use (&$firstUnderlying) {
            $firstUnderlying->resolve('first confirmed');
        });
        $secondUnderlying = new Promise(function () use (&$secondUnderlying) {
            $secondUnderlying->resolve('second confirmed');
        });

        $pool->dispatch(fn () => $firstUnderlying);
        $queuedProxy = $pool->dispatch(fn () => $secondUnderlying);

        $this->assertSame('second confirmed', $queuedProxy->wait());
        $this->assertSame(PromiseInterface::FULFILLED, $firstUnderlying->getState());
    }
}
