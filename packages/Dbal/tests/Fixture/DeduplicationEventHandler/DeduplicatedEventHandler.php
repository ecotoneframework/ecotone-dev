<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeduplicationEventHandler;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\Deduplicated;
use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;

/**
 * licence Apache-2.0
 */
final class DeduplicatedEventHandler
{
    private int $called = 0;

    #[Deduplicated]
    #[Asynchronous('async')]
    #[EventHandler('order.was_placed', 'handleOne')]
    public function handleOne(): void
    {
        $this->called++;
    }

    #[Deduplicated]
    #[Asynchronous('async')]
    #[EventHandler('order.was_placed', 'handleTwo')]
    public function handleTwo(): void
    {
        $this->called++;
    }

    #[Asynchronous('async')]
    #[EventHandler('order.was_cancelled', 'handleGlobalOne')]
    public function handleGlobalOne(): void
    {
        $this->called++;
    }

    #[Asynchronous('async')]
    #[EventHandler('order.was_cancelled', 'handleGlobalTwo')]
    public function handleGlobalTwo(): void
    {
        $this->called++;
    }

    #[QueryHandler('email_event_handler.getCallCount')]
    public function getCallCount(): int
    {
        return $this->called;
    }
}
