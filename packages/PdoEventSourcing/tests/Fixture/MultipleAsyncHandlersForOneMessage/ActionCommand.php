<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultipleAsyncHandlersForOneMessage;

final class ActionCommand
{
    public function __construct(public string $id)
    {
    }
}
