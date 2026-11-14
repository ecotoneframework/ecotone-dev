<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Support\FrameworkHeaders;
use Ecotone\Messaging\Support\MessageBuilder;

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
 */
final class GatheringConflictRedelivery
{
    public function __construct(private ChannelResolver $channelResolver, private MessageBuilder $messageBuilder)
    {
    }

    public function resendCollected(Message $message, string $channelName): void
    {
        $this->resend($this->messageBuilder->fromMessage($message)->setHeader(CollectingMessageIdentity::ORIGINAL_MESSAGE_ID, CollectingMessageIdentity::of($message))->build(), $channelName);
    }

    public function resend(Message $message, string $channelName): void
    {
        $headers = FrameworkHeaders::unsetNonUserKeys($message->getHeaders()->headers());
        if ($message->getHeaders()->containsKey(MessageHeaders::TYPE_ID)) {
            $headers[MessageHeaders::TYPE_ID] = $message->getHeaders()->get(MessageHeaders::TYPE_ID);
        }
        $replacement = $this->messageBuilder->withPayload($message->getPayload())
            ->setMultipleHeaders($headers)
            ->removeHeaders([MessageHeaders::MESSAGE_ID, MessageHeaders::TIMESTAMP, MessageHeaders::ROUTING_SLIP])
            ->setContentType($message->getHeaders()->hasContentType() ? $message->getHeaders()->getContentType() : MediaType::createApplicationXPHP())
            ->build();
        $this->channelResolver->resolve($channelName)->send($replacement);
    }

}
