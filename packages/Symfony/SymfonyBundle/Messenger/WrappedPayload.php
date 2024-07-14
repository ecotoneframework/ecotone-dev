<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

/**
 * licence Apache-2.0
 */
final class WrappedPayload
{
    public function __construct(private mixed $payload)
    {

    }

    public function getPayload(): mixed
    {
        return $this->payload;
    }
}
