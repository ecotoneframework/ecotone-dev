<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use Doctrine\DBAL\ArrayParameterType;
use Ecotone\Dbal\Attribute\DbalParameter;
use Ecotone\Dbal\Attribute\DbalQueryBusinessMethod;

interface ParameterDbalTypeConversion
{
    #[DbalQueryBusinessMethod('SELECT person_id, name FROM persons WHERE person_id IN (:personIds)')]
    public function getPersonsWith(
        #[DbalParameter(type: ArrayParameterType::INTEGER)] array $personIds
    ): array;

    #[DbalQueryBusinessMethod('SELECT person_id, name FROM persons WHERE person_id IN (:personIds)')]
    #[DbalParameter("personIds", type: ArrayParameterType::INTEGER, expression: '[1]')]
    public function getPersonsWithWithMethodLevelParameter(): array;
}