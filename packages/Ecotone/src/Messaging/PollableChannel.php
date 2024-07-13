<?php

declare(strict_types=1);

namespace Ecotone\Messaging;

/**
 * Interface PollableChannel
 * @package Ecotone\Messaging
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface PollableChannel extends MessageChannel, MessagePoller
{
    /**
     * Receive a message from this channel.
     * Return the next available {@see Message} or {@see null} if interrupted.
     */
    public function receive(): ?Message;
}
