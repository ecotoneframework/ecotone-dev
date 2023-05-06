<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

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
