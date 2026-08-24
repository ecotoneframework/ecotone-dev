<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Counter;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\QueryHandler;

/**
 * licence Apache-2.0
 */
final class CounterService
{
    private int $count = 0;

    #[CommandHandler('counter.increment')]
    public function increment(): void
    {
        $this->count++;
    }

    #[QueryHandler('counter.get')]
    public function get(): int
    {
        return $this->count;
    }
}
