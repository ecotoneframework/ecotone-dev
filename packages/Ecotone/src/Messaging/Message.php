<?php

namespace Ecotone\Messaging;

use Ecotone\Api\Messaging\MessageHeaders;

/**
 * licence Apache-2.0
 */
interface Message
{
    public function getHeaders(): MessageHeaders;

    public function getPayload(): mixed;
}
