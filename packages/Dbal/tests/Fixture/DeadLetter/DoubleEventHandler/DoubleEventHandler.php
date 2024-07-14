<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\DoubleEventHandler;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class DoubleEventHandler
{
    private int $callCount = 0;
    public int $successfulCalls = 0;

    #[Asynchronous('async')]
    #[EventHandler(endpointId: 'first')]
    public function handleOne(ExampleEvent $event): void
    {
        $this->callCount += 1;

        if ($this->callCount > 2) {
            $this->successfulCalls++;

            return;
        }

        throw new InvalidArgumentException('exception');
    }

    #[Asynchronous('async')]
    #[EventHandler(endpointId: 'second')]
    public function handleTwo(ExampleEvent $event): void
    {
        $this->callCount += 1;

        if ($this->callCount > 2) {
            $this->successfulCalls++;

            return;
        }

        throw new InvalidArgumentException('exception');
    }
}
