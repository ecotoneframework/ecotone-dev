<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies;

use Ecotone\Api\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
/**
 * licence Apache-2.0
 */
final class BasketCreated
{
    public const NAME = 'basket_created';

    public function __construct(
        public string $basketId,
    ) {
    }
}
