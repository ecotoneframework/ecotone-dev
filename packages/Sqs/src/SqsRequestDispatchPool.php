<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Closure;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Throwable;

/**
 * licence Enterprise
 */
final class SqsRequestDispatchPool
{
    public const DEFAULT_MAX_CONCURRENT_REQUESTS = 25;

    /** @var array<int, array{proxy: Promise, underlying: ?PromiseInterface}> */
    private array $trackedRequests = [];

    /** @var array<int, Closure> */
    private array $awaitingDispatch = [];

    /** @var array<int, PromiseInterface> */
    private array $inFlightRequests = [];

    private int $nextRequestIndex = 0;

    public function __construct(private int $maxConcurrentRequests = self::DEFAULT_MAX_CONCURRENT_REQUESTS)
    {
    }

    public function dispatch(Closure $dispatchSendRequest): PromiseInterface
    {
        $requestIndex = $this->nextRequestIndex++;
        $proxy = new Promise(fn () => $this->driveUntilSettled($requestIndex));
        $this->trackedRequests[$requestIndex] = ['proxy' => $proxy, 'underlying' => null];
        $this->awaitingDispatch[$requestIndex] = $dispatchSendRequest;
        $this->dispatchWithinBudget();

        return $proxy;
    }

    private function dispatchWithinBudget(): void
    {
        while (count($this->inFlightRequests) < $this->maxConcurrentRequests && $this->awaitingDispatch !== []) {
            $requestIndex = array_key_first($this->awaitingDispatch);
            $dispatchSendRequest = $this->awaitingDispatch[$requestIndex];
            unset($this->awaitingDispatch[$requestIndex]);

            try {
                $underlying = $dispatchSendRequest();
            } catch (Throwable $dispatchFailure) {
                $proxy = $this->trackedRequests[$requestIndex]['proxy'];
                unset($this->trackedRequests[$requestIndex]);
                $proxy->reject($dispatchFailure);

                continue;
            }

            $this->trackedRequests[$requestIndex]['underlying'] = $underlying;
            $this->inFlightRequests[$requestIndex] = $underlying;

            $underlying->then(
                function (mixed $value) use ($requestIndex): void {
                    $this->settleProxy($requestIndex, fn (Promise $proxy) => $proxy->resolve($value));
                },
                function (mixed $reason) use ($requestIndex): void {
                    $this->settleProxy($requestIndex, fn (Promise $proxy) => $proxy->reject($reason));
                },
            );
        }
    }

    private function settleProxy(int $requestIndex, Closure $settle): void
    {
        unset($this->inFlightRequests[$requestIndex]);
        $this->dispatchWithinBudget();

        $proxy = $this->trackedRequests[$requestIndex]['proxy'];
        unset($this->trackedRequests[$requestIndex]);
        $settle($proxy);
    }

    private function driveUntilSettled(int $requestIndex): void
    {
        while (isset($this->trackedRequests[$requestIndex]) && $this->trackedRequests[$requestIndex]['proxy']->getState() === PromiseInterface::PENDING) {
            $underlying = $this->trackedRequests[$requestIndex]['underlying'];
            if ($underlying !== null) {
                $underlying->wait(false);

                continue;
            }

            if ($this->inFlightRequests !== []) {
                $this->inFlightRequests[array_key_first($this->inFlightRequests)]->wait(false);

                continue;
            }

            $this->dispatchWithinBudget();
        }
    }
}
