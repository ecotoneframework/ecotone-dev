<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Channel\EventDrivenChannelInterceptorAdapter;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedEventDrivenChannelInterceptorAdapter extends EventDrivenChannelInterceptorAdapter
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
}
