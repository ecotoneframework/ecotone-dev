<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Channel\PollableChannelInterceptorAdapter;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedPollableChannelInterceptorAdapter extends PollableChannelInterceptorAdapter
{
    /**
     * @param ChannelInterceptor[] $sortedChannelInterceptors
     */
    public function __construct(MessageChannel $messageChannel, array $sortedChannelInterceptors, private ChannelSendRecording $recording)
    {
        parent::__construct($messageChannel, $sortedChannelInterceptors);
    }

    public function send(Message $message): void
    {
        $this->recording->started($message);
        try {
            parent::send($message);
        } catch (Throwable $failure) {
            $this->recording->completed($failure);

            throw $failure;
        }
        $this->recording->completed(null);
    }

    public function receive(): ?Message
    {
        $message = parent::receive();

        return $message === null ? null : $this->recording->received($message);
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        $message = parent::receiveWithTimeout($pollingMetadata);

        return $message === null ? null : $this->recording->received($message);
    }
}
