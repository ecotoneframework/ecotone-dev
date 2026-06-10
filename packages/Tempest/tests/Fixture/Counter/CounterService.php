<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Counter;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

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
