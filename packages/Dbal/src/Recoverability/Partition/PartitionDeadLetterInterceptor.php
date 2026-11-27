<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\ErrorChannelService;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;

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
final class PartitionDeadLetterInterceptor
{
    public function __construct(
        private PartitionedHandlers $partitionedHandlers,
        private HeldPartitions $heldPartitions,
        private PartitionReplay $partitionReplay,
        private ErrorChannelService $errorChannelService,
        private ChannelResolver $channelResolver,
        private MessageHeadersPropagatorInterceptor $headersPropagator,
        private string $deadLetterChannelName,
    ) {
    }

    public function handle(MethodInvocation $methodInvocation, Message $message): mixed
    {
        $partition = $this->partitionedHandlers->partitionOf($message);
        if ($partition === null) {
            return $methodInvocation->proceed();
        }

        if ($message->getHeaders()->containsKey(MessageHeaders::DEAD_LETTER_PARTITION_ORDER_IGNORED)) {
            return $methodInvocation->proceed();
        }
        if ($message->getHeaders()->containsKey(MessageHeaders::DEAD_LETTER_PARTITION_RELEASE)) {
            $result = $methodInvocation->proceed();
            $this->headersPropagator->storeHeaders(fn (): bool => $this->partitionReplay->continueWith($partition), $message);

            return $result;
        }

        $holdingDeadLetterId = $this->headersPropagator->storeHeaders(fn (): ?string => $this->heldPartitions->oldestOf($partition), $message);
        if ($holdingDeadLetterId === null) {
            return $methodInvocation->proceed();
        }

        $this->errorChannelService->handle(
            requestMessage: $message,
            cause: PartitionHeldInDeadLetter::since($partition, $holdingDeadLetterId),
            errorChannel: $this->channelResolver->resolve($this->deadLetterChannelName),
            relatedPolledChannelName: $message->getHeaders()->containsKey(MessageHeaders::POLLED_CHANNEL_NAME) ? $message->getHeaders()->get(MessageHeaders::POLLED_CHANNEL_NAME) : null,
        );

        return null;
    }
}
