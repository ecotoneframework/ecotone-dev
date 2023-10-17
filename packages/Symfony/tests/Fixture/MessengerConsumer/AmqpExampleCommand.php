<?php

declare(strict_types=1);

namespace Fixture\MessengerConsumer;

final class AmqpExampleCommand
{
    public function __construct(public string $id = '1')
    {
    }
}
