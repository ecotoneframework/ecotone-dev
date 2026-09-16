<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Fixture\ServiceParameter;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Psr\Clock\ClockInterface;

/**
 * licence Apache-2.0
 */
#[Aggregate]
final class Basket
{
    #[Identifier]
    public string $basketId;

    public ?string $clearedAt = null;

    #[CommandHandler('basket.create')]
    public static function create(string $basketId): self
    {
        $basket = new self();
        $basket->basketId = $basketId;

        return $basket;
    }

    #[CommandHandler('basket.clear')]
    public function clear(ClockInterface $clock): void
    {
        $this->clearedAt = $clock->now()->format(DATE_ATOM);
    }
}
