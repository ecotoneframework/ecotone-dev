<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\MultiTenant;

use Ecotone\Dbal\Attribute\DbalQuery;
use Ecotone\Dbal\DbaBusinessMethod\FetchMode;

/**
 * licence Apache-2.0
 */
interface CustomerRepository
{
    #[DbalQuery('SELECT customer_id FROM persons', fetchMode: FetchMode::FIRST_COLUMN)]
    public function getAllRegisteredPersonIds(): array;
}
