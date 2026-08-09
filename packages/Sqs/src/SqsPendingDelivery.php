<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Aws\Result;
use Ecotone\Messaging\Channel\DeliveryConfirmation\DeliveryResult;
use Ecotone\Messaging\Channel\DeliveryConfirmation\FailedDelivery;
use Ecotone\Messaging\Channel\DeliveryConfirmation\PendingDelivery;
use Ecotone\Messaging\Message;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Throwable;

/**
 * licence Enterprise
 */
final class SqsPendingDelivery implements PendingDelivery
{
    private bool $awaited = false;

    private ?DeliveryResult $deliveryResult = null;

    /**
     * @param PromiseInterface[] $sendRequestPromises keyed by request index, dispatched with bounded concurrency through SqsRequestDispatchPool
     * @param array<int, array<string, Message>> $trackedMessagesPerRequest keyed by request index, then by batch entry id
     */
    public function __construct(
        private array $sendRequestPromises,
        private array $trackedMessagesPerRequest,
        private string $channelName,
    ) {
    }

    public function awaitDelivery(): DeliveryResult
    {
        if ($this->deliveryResult !== null) {
            return $this->deliveryResult;
        }

        $settledResults = Utils::settle($this->sendRequestPromises)->wait();
        $this->awaited = true;

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

        return $this->deliveryResult = ($failedDeliveries === []
            ? DeliveryResult::successful()
            : DeliveryResult::withFailedDeliveries($failedDeliveries));
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }
}
