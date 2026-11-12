<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Messaging\GatheredParts;
use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\MessageProcessor;
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
final class GatheringSplitterProcessor implements MessageProcessor
{
    public function __construct(
        private MessageProcessor $splitter,
        private ChannelResolver $channelResolver,
        private MessageBuilder $messageBuilder,
        private string $gatherChannelName,
        private string $splitterEndpointId,
        private GatheredGroupStore $store,
    ) {
    }

    public function process(Message $message): ?Message
    {
        $reply = $this->splitter->process($message);
        if ($reply !== null && $reply->getPayload() === []) {
            $gathered = new GatheredParts(
                results: [],
                missingPartNumbers: [],
                size: 0,
                originalPayload: $message->getPayload(),
            );
            $this->channelResolver->resolve($this->gatherChannelName)->send(
                $this->messageBuilder->fromParentMessage($message)
                    ->setPayload($gathered)
                    ->setContentType(MediaType::createApplicationXPHP())
                    ->build()
            );
        }

        if ($reply !== null && count($reply->getPayload()) > 0) {
            $this->store->openGroup(
                groupId: hash('sha256', $message->getHeaders()->getMessageId() . ':' . $this->splitterEndpointId),
                gatherChannelName: $this->gatherChannelName,
                size: count($reply->getPayload()),
            );
        }

        return $reply;
    }
}
