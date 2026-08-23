<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\NonPollableChannel;

/**
 * licence Apache-2.0
 */
final class DirectChannelPayload
{
    public function __construct(public string $name)
    {
    }
}
