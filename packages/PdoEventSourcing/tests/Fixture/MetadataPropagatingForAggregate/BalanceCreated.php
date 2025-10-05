<?php

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate;

use Ramsey\Uuid\Rfc4122\UuidV4;

/**
 * licence Apache-2.0
 */
class BalanceCreated
{
    public function __construct(public UuidV4 $balanceId)
    {
    }
}
