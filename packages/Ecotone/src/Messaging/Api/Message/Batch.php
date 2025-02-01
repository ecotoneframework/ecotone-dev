<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Api\Message;

final class Batch
{
    /**
     * @param BatchedMessage[] $messages
     */
    public function __construct(
        public readonly array $messages
    ) {}
}