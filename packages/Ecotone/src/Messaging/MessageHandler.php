<?php

declare(strict_types=1);

namespace Ecotone\Messaging;

/**
 * Interface MessageHandler
 * @package Ecotone\Messaging
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
interface MessageHandler
{
    /**
     * Handles given message
     */
    public function handle(Message $message): void;
}
