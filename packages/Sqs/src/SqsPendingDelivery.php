<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Aws\Result;
use Closure;
use Ecotone\Messaging\Channel\AsyncPublishing\DeliveryResult;
use Ecotone\Messaging\Channel\AsyncPublishing\FailedDelivery;
use Ecotone\Messaging\Channel\AsyncPublishing\PendingDelivery;
use Ecotone\Messaging\Message;
use GuzzleHttp\Promise\Each;
use GuzzleHttp\Promise\PromiseInterface;
use Throwable;

/**
 * licence Enterprise
 */
final class SqsPendingDelivery implements PendingDelivery
{
    public const DEFAULT_MAX_CONCURRENT_REQUESTS = 25;

    private bool $awaited = false;

    /**
     * @param Closure[] $sendRequestDispatchers each returns a PromiseInterface when invoked, so requests are dispatched lazily with bounded concurrency
     * @param array<int, array<string, Message>> $trackedMessagesPerRequest keyed by request index, then by batch entry id
     */
    public function __construct(
        private array $sendRequestDispatchers,
        private array $trackedMessagesPerRequest,
        private string $channelName,
        private int $maxConcurrentRequests = self::DEFAULT_MAX_CONCURRENT_REQUESTS,
    ) {
    }

    public function awaitDelivery(): DeliveryResult
    {
        $this->awaited = true;

        $settledResults = $this->dispatchWithBoundedConcurrency();

        $failedDeliveries = [];
        foreach ($this->trackedMessagesPerRequest as $requestIndex => $trackedMessages) {
            $settledResult = $settledResults[$requestIndex] ?? ['state' => PromiseInterface::REJECTED, 'reason' => 'SQS send request was never dispatched'];

            if ($settledResult['state'] !== PromiseInterface::FULFILLED) {
                $failureReason = $settledResult['reason'] instanceof Throwable
                    ? $settledResult['reason']->getMessage()
                    : (string) $settledResult['reason'];

                foreach ($trackedMessages as $trackedMessage) {
                    $failedDeliveries[] = new FailedDelivery($trackedMessage, $failureReason, $this->channelName);
                }

                continue;
            }

            /** @var Result $awsResult */
            $awsResult = $settledResult['value'];
            $unaccountedEntryIds = array_map('strval', array_keys($trackedMessages));

            foreach ($awsResult->get('Successful') ?? [] as $successfulEntry) {
                $unaccountedEntryIds = array_diff($unaccountedEntryIds, [(string) $successfulEntry['Id']]);
            }

            foreach ($awsResult->get('Failed') ?? [] as $failedEntry) {
                $entryId = (string) $failedEntry['Id'];
                $unaccountedEntryIds = array_diff($unaccountedEntryIds, [$entryId]);

                if (isset($trackedMessages[$entryId])) {
                    $failedDeliveries[] = new FailedDelivery(
                        $trackedMessages[$entryId],
                        sprintf('%s: %s', $failedEntry['Code'] ?? 'Unknown', $failedEntry['Message'] ?? 'SQS rejected batch entry'),
                        $this->channelName,
                    );
                }
            }

            foreach ($unaccountedEntryIds as $unaccountedEntryId) {
                $failedDeliveries[] = new FailedDelivery(
                    $trackedMessages[$unaccountedEntryId],
                    'SQS did not confirm delivery of the batch entry',
                    $this->channelName,
                );
            }
        }

        return $failedDeliveries === []
            ? DeliveryResult::successful()
            : DeliveryResult::withFailedDeliveries($failedDeliveries);
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }

    /**
     * @return array<int, array{state: string, value?: mixed, reason?: mixed}>
     */
    private function dispatchWithBoundedConcurrency(): array
    {
        $settledResults = [];
        $sendRequestPromises = (function () use (&$settledResults) {
            foreach ($this->sendRequestDispatchers as $requestIndex => $dispatchSendRequest) {
                yield $dispatchSendRequest()->then(
                    function (mixed $value) use (&$settledResults, $requestIndex): void {
                        $settledResults[$requestIndex] = ['state' => PromiseInterface::FULFILLED, 'value' => $value];
                    },
                    function (mixed $reason) use (&$settledResults, $requestIndex): void {
                        $settledResults[$requestIndex] = ['state' => PromiseInterface::REJECTED, 'reason' => $reason];
                    },
                );
            }
        })();

        Each::ofLimit($sendRequestPromises, $this->maxConcurrentRequests)->wait();

        return $settledResults;
    }
}
