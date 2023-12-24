<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Ecotone\Dbal\Attribute\DbalParameter;
use Ecotone\Dbal\Attribute\DbalWriteBusinessMethod;
use Ecotone\Messaging\Conversion\MediaType;

interface PersonWriteApi
{
    #[DbalWriteBusinessMethod("INSERT INTO persons VALUES (:personId, :name)")]
    public function insert(int $personId, string $name): void;

    #[DbalWriteBusinessMethod('UPDATE persons SET name = :name WHERE person_id = :personId')]
    public function changeName(int $personId, string $name): int;

    /**
     * @param string[] $roles
     */
    #[DbalWriteBusinessMethod('UPDATE persons SET roles = :roles WHERE person_id = :personId')]
    public function changeRoles(
        int $personId,
        #[DbalParameter(convertToMediaType: MediaType::APPLICATION_JSON)] array $roles
    ): void;

    /**
     * @param PersonRole[] $roles
     */
    #[DbalWriteBusinessMethod('UPDATE persons SET roles = :roles WHERE person_id = :personId')]
    public function changeRolesWithValueObjects(
        int $personId,
        #[DbalParameter(convertToMediaType: MediaType::APPLICATION_JSON)] array $roles
    ): void;
}