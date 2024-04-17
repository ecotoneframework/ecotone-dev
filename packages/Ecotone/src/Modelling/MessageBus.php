<?php

declare(strict_types=1);

namespace Ecotone\Modelling;

interface MessageBus
{
    /**
     * @param string $targetChannel Channel name to send message to
     */
    public function send(
        string $targetChannel,
        mixed  $payload,
        array  $metadata = []
    ): mixed;
}
