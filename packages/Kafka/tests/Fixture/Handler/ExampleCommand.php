<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\Handler;

/**
 * licence Apache-2.0
 */
final class ExampleCommand
{
    public function __construct(public string $id = '1')
    {

    }
}
