<?php

namespace Test\Ecotone\Modelling\Fixture\NamedEvent;

use Ecotone\Api\Repository;
use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\InMemoryStateStoredRepository;

#[Repository]
/**
 * licence Apache-2.0
 */
class GuestBookRepository extends InMemoryStateStoredRepository
{
}
