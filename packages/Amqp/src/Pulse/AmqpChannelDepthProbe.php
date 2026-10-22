<?php

declare(strict_types=1);

namespace Ecotone\Amqp\Pulse;

use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseChannelDepth;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseChannelProbe;
use Enqueue\AmqpLib\AmqpContext as AmqpLibContext;
use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpQueue;
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
final class AmqpChannelDepthProbe implements PulseChannelProbe
{
    public function __construct(private ConnectionFactory $connectionFactory)
    {
    }

    public function depthsOf(array $destinationByChannel, int $budgetInMilliseconds): array
    {
        $depths = [];
        foreach ($destinationByChannel as $channel => $queueName) {
            $depths[$channel] = $this->depthOf($queueName);
        }

        return $depths;
    }

    private function depthOf(string $queueName): PulseChannelDepth
    {
        $context = $this->connectionFactory->createContext();
        try {
            if ($context instanceof AmqpLibContext) {
                [, $ready, $consumers] = $context->getLibChannel()->queue_declare($queueName, true);

                return PulseChannelDepth::measured((int) $ready, consumers: (int) $consumers > 0 ? (int) $consumers : null, setUp: true);
            }

            /** @var AmqpContext $context */
            $queue = $context->createQueue($queueName);
            $queue->addFlag(AmqpQueue::FLAG_PASSIVE);

            return PulseChannelDepth::measured($context->declareQueue($queue), setUp: true);
        } catch (Throwable $failure) {
            if ($this->isMissingQueue($failure)) {
                return PulseChannelDepth::missing();
            }

            return PulseChannelDepth::failed("RabbitMQ queue '{$queueName}' could not be inspected: {$failure->getMessage()}. Declare the queue by starting a consumer or sending a message to the channel, or point the channel at an existing queue.");
        } finally {
            $context->close();
        }
    }

    private function isMissingQueue(Throwable $failure): bool
    {
        return $failure->getCode() === 404 || str_contains($failure->getMessage(), 'NOT_FOUND');
    }
}
