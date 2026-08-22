<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\OrderAggregate;

use Ecotone\Modelling\Attribute\Repository;
use Ecotone\Modelling\InMemoryStateStoredRepository;

#[Repository]
/**
 * licence Apache-2.0
 */
class OrderRepository extends InMemoryStateStoredRepository
{
}
