<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ClosureInAttribute;

use Ecotone\Dbal\Attribute\DbalParameter;
use Ecotone\Dbal\Attribute\DbalQuery;
use Ecotone\Dbal\Attribute\DbalWrite;
use Ecotone\Dbal\DbaBusinessMethod\FetchMode;

/**
 * licence Apache-2.0
 */
interface PersonClosureParameterApi
{
    #[DbalWrite('INSERT INTO persons (person_id, name) VALUES (:personId, :fullName)')]
    #[DbalParameter('fullName', expression: static function (string $firstName, string $lastName): string {
        return $firstName . ' ' . $lastName;
    })]
    public function insertWithMethodLevelClosure(int $personId, string $firstName, string $lastName): void;

    #[DbalWrite('INSERT INTO persons (person_id, name) VALUES (:personId, :name)')]
    public function insertWithParameterLevelClosure(
        int $personId,
        #[DbalParameter(expression: static function (string $payload): string {
            return strtolower($payload);
        })] string $name,
    ): void;

    #[DbalWrite('INSERT INTO persons (person_id, name) VALUES (:personId, :titledName)')]
    #[DbalParameter('titledName', expression: static function (string $name, string $title = 'Sir'): string {
        return $title . ' ' . $name;
    })]
    public function insertWithTitledName(int $personId, string $name): void;

    #[DbalQuery(
        'SELECT name FROM persons WHERE person_id = :personId',
        fetchMode: FetchMode::FIRST_COLUMN_OF_FIRST_ROW
    )]
    public function getNameById(int $personId): string;
}
