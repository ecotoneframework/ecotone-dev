<?php

declare(strict_types=1);

namespace Ecotone\Api\Messaging;

use Stringable;

/**
 * Interface MessageChannel
 * @package Ecotone\Messaging
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
interface MessageChannel extends Stringable
{
    /**
     * Send message to this channel
     */
    public function send(Message $message): void;
}
