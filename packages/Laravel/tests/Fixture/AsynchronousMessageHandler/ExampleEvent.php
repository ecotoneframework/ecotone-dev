<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\AsynchronousMessageHandler;

/**
 * licence Apache-2.0
 */
final class ExampleEvent
{
    public function __construct(public string $id = '1')
    {

    }
}
