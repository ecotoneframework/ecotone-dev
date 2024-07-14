<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
/**
 * licence Apache-2.0
 */
final class OrderCreated
{
    public const NAME = 'order_created';

    public function __construct(
        public string $orderId,
    ) {
    }
}
