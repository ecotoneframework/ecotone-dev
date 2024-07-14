<?php

declare(strict_types=1);

namespace Fixture\MessengerConsumer;

/**
 * licence Apache-2.0
 */
final class AmqpExampleCommand
{
    public function __construct(public string $id = '1')
    {
    }
}
