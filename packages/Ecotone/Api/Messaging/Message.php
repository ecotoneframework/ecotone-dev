<?php

namespace Ecotone\Api\Messaging;

/**
 * licence Apache-2.0
 */
interface Message
{
    public function getHeaders(): MessageHeaders;

    public function getPayload(): mixed;
}
