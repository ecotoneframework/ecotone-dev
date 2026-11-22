<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Coroutine;

use Closure;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Messaging\MessagePoller;
use Ecotone\Messaging\Execution\ExecutionScope;
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
 */
final class CoroutineMessageChannelReplayedMessage implements MessagePoller
{
    public const SERVICE_ID_PREFIX = 'ecotone.coroutine_message_channel.replayed_message.';

    public function __construct(private string $channelName, private MessagePoller $channelMessages, private MessageBuilder $messageBuilder)
    {
    }

    public function replayWith(Message $message, Closure $consume): void
    {
        $state = $this->state();
        $state->message = $message;
        $state->replaying = true;
        try {
            $consume();
        } finally {
            $state->message = null;
            $state->replaying = false;
        }
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        $state = $this->state();
        if (! $state->replaying) {
            return $this->channelMessages->receiveWithTimeout($pollingMetadata);
        }
        $message = $state->message;
        $state->message = null;
        if ($message === null) {
            return null;
        }

        return $this->messageBuilder->fromMessage($message)
            ->setHeader(MessageHeaders::POLLED_CHANNEL_NAME, $this->channelName)
            ->build();
    }

    public function onConsumerStop(): void
    {
        $this->channelMessages->onConsumerStop();
    }

    private function state(): CoroutineMessageChannelReplayState
    {
        return ExecutionScope::stateOf($this, static fn () => new CoroutineMessageChannelReplayState());
    }
}
