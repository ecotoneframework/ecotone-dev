<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use Doctrine\DBAL\Connection;
use Ecotone\Dbal\Attribute\DbalParameter;
use Ecotone\Dbal\Attribute\DbalQueryBusinessMethod;

interface ParameterDbalTypeConversion
{
    #[DbalQueryBusinessMethod('SELECT person_id, name FROM persons WHERE person_id IN (:personIds)')]
    public function getPersonsWith(
        #[DbalParameter(type: Connection::PARAM_INT_ARRAY)] array $personIds
    ): array;

    #[DbalQueryBusinessMethod('SELECT person_id, name FROM persons WHERE person_id IN (:personIds)')]
    #[DbalParameter('personIds', type: Connection::PARAM_INT_ARRAY, expression: '[1]')]
    public function getPersonsWithWithMethodLevelParameter(): array;

    #[DbalQueryBusinessMethod('SELECT person_id, name FROM persons WHERE person_id IN (:personIds)')]
    public function getPersonsWithAutoresolve(
        array $personIds
    ): array;

    #[DbalQueryBusinessMethod('SELECT person_id, name FROM persons WHERE name IN (:names)')]
    #[DbalParameter('names', expression: "['John']")]
    public function getPersonsWithMethodLevelParameterAndAutoresolve(
        array $names
    ): array;
}
