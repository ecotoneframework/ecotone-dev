<?php

namespace Test\Ecotone\Modelling\Fixture\TwoSagas;

use Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate\InMemoryStateStoredRepository;

#[\Ecotone\Modelling\Attribute\Repository]
/**
 * licence Apache-2.0
 */
class TwoSagasRepository extends InMemoryStateStoredRepository
{
}
