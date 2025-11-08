<?php

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\Config\Container\CompilableBuilder;

/**
 * Interface MessageChannelBuilder
 * @package Ecotone\Messaging\Channel
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
interface MessageChannelBuilder extends CompilableBuilder
{
    /**
     * @return string
     */
    public function getMessageChannelName(): string;

    /**
     * @return bool
     */
    public function isPollable(): bool;

    /**
     * Get the endpoint ID for this channel
     *
     * For most channels, this returns the channel name to preserve current behavior.
     * For shared channels (Kafka, AMQP Stream), this returns the message group ID used for tracking.
     *
     * @return string
     */
    public function getEndpointId(): string;
}
