<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\AsynchronousMessageHandler;

final class ExampleCommand
{
    public function __construct(public string $id = '1')
    {

    }
}
