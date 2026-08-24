<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\PersonQueryHandler;

use Ecotone\Api\QueryHandler;
use Ecotone\Api\Reference;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;

/**
 * licence Apache-2.0
 */
final class PersonQueryService
{
    /**
     * @return int[]
     */
    #[QueryHandler('person.getAllIds')]
    public function getAllPersonIds(#[Reference(DbalConnectionFactory::class)] ConnectionFactory $connectionFactory): array
    {
        return $connectionFactory->createContext()->getDbalConnection()->fetchFirstColumn('SELECT person_id FROM persons ORDER BY person_id ASC');
    }
}
