<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\PersonQueryHandler;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\QueryHandler;
use Enqueue\Dbal\DbalConnectionFactory;
use Interop\Queue\ConnectionFactory;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\PersonRepository\ORMPersonRepository;

final readonly class PersonQueryService
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