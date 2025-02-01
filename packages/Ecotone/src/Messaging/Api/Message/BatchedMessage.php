<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Api\Message;

use Ecotone\Messaging\Support\Assert;

final class BatchedMessage
{
    /**
     * @param mixed $payload
     * @param array<string, string> $metadata
     */
    public function __construct(
        public readonly mixed $payload,
        public readonly array $metadata = []
    )
    {
        Assert::allStrings(array_keys($metadata), "Metadata keys must be strings");
    }
}