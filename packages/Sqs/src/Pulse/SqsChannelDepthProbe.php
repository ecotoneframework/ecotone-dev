<?php

declare(strict_types=1);

namespace Ecotone\Sqs\Pulse;

use Aws\Exception\AwsException;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseChannelDepth;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseChannelProbe;
use Enqueue\Sqs\SqsContext;
use Interop\Queue\ConnectionFactory;
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
 *
 * @internal
 */
final class SqsChannelDepthProbe implements PulseChannelProbe
{
    public function __construct(private ConnectionFactory $connectionFactory)
    {
    }

    public function depthsOf(array $destinationByChannel, int $budgetInMilliseconds): array
    {
        /** @var SqsContext $context */
        $context = $this->connectionFactory->createContext();

        $depths = [];
        foreach ($destinationByChannel as $channel => $queueName) {
            $depths[$channel] = $this->depthOf($context, $queueName);
        }

        return $depths;
    }

    private function depthOf(SqsContext $context, string $queueName): PulseChannelDepth
    {
        try {
            $attributes = $context->getAwsSqsClient()->getQueueAttributes([
                'QueueUrl' => $context->getQueueUrl($context->createQueue($queueName)),
                'AttributeNames' => ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible', 'ApproximateNumberOfMessagesDelayed'],
            ])->get('Attributes');
        } catch (Throwable $failure) {
            if ($this->isMissingQueue($failure)) {
                return PulseChannelDepth::missing();
            }

            return PulseChannelDepth::failed("SQS queue '{$queueName}' could not be inspected: {$failure->getMessage()} Create the queue by sending a message to the channel or starting its consumer, or point the channel at an existing queue.");
        }

        return PulseChannelDepth::measured(
            (int) $attributes['ApproximateNumberOfMessages'],
            (int) $attributes['ApproximateNumberOfMessagesDelayed'],
            (int) $attributes['ApproximateNumberOfMessagesNotVisible'],
            setUp: true,
        );
    }

    private function isMissingQueue(Throwable $failure): bool
    {
        return $failure instanceof AwsException && in_array($failure->getAwsErrorCode(), ['AWS.SimpleQueueService.NonExistentQueue', 'QueueDoesNotExist'], true);
    }
}
