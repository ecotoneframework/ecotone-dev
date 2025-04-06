<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;

// Define constants for compatibility with both DBAL 3.x and 4.x
if (!defined('DBAL_ARRAY_PARAM_INT')) {
    define('DBAL_ARRAY_PARAM_INT', class_exists('\Doctrine\DBAL\ArrayParameterType') ? 1 : 102);
}
if (!defined('DBAL_ARRAY_PARAM_STR')) {
    define('DBAL_ARRAY_PARAM_STR', class_exists('\Doctrine\DBAL\ArrayParameterType') ? 2 : 101);
}
use Ecotone\Dbal\Attribute\DbalParameter;
use Ecotone\Dbal\Attribute\DbalQuery;

/**
 * licence Apache-2.0
 */
interface ParameterDbalTypeConversion
{
    #[DbalQuery('SELECT person_id, name FROM persons WHERE person_id IN (:personIds)')]
    public function getPersonsWith(
        #[DbalParameter(type: DBAL_ARRAY_PARAM_INT)] array $personIds
    ): array;

    #[DbalQuery('SELECT person_id, name FROM persons WHERE person_id IN (:personIds)')]
    #[DbalParameter('personIds', type: DBAL_ARRAY_PARAM_INT, expression: '[1]')]
    public function getPersonsWithWithMethodLevelParameter(): array;

    #[DbalQuery('SELECT person_id, name FROM persons WHERE person_id IN (:personIds)')]
    public function getPersonsWithAutoresolve(
        array $personIds
    ): array;

    #[DbalQuery('SELECT person_id, name FROM persons WHERE name IN (:names)')]
    #[DbalParameter('names', expression: "['John']")]
    public function getPersonsWithMethodLevelParameterAndAutoresolve(
        array $names
    ): array;

    #[DbalQuery('SELECT person_id, name FROM persons LIMIT :limit OFFSET :offset')]
    #[DbalParameter('limit', expression: 'pagination.limit')]
    #[DbalParameter('offset', expression: 'pagination.offset')]
    public function getNameListWithIgnoredParameters(
        Pagination $pagination
    ): array;

    #[DbalQuery('SELECT person_id, name FROM persons LIMIT :limit OFFSET :offset')]
    #[DbalParameter('limit', expression: 'limitParameter')]
    #[DbalParameter('offset', expression: 'offsetParameter')]
    public function getNameListWithMultipleIgnoredParameters(
        int $limitParameter,
        int $offsetParameter
    ): array;

    #[DbalQuery('SELECT person_id, name FROM persons LIMIT :(pagination.limit) OFFSET :(pagination.offset)')]
    public function usingExpressionLanguageWithSQL(
        Pagination $pagination
    ): array;
}
