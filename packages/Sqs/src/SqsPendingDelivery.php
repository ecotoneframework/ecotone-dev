<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Aws\Result;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Channel\DeliveryConfirmation\DeliveryResult;
use Ecotone\Messaging\Channel\DeliveryConfirmation\FailedDelivery;
use Ecotone\Messaging\Channel\DeliveryConfirmation\PendingDelivery;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Throwable;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
