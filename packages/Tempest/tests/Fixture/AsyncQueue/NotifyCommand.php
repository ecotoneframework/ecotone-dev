<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\AsyncQueue;

/**
 * licence Apache-2.0
 */
final class NotifyCommand
{
    public function __construct(public readonly string $message)
    {
    }
}
