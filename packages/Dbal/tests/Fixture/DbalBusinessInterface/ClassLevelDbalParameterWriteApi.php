<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use Ecotone\Api\Dbal\DbalParameter;
use Ecotone\Api\Dbal\DbalWrite;
use Ecotone\Messaging\Conversion\MediaType;

#[DbalParameter(name: 'roles', expression: "name === 'Johny' ? ['ROLE_ADMIN'] : []", convertToMediaType: MediaType::APPLICATION_JSON)]
/**
 * licence Apache-2.0
 */
interface ClassLevelDbalParameterWriteApi
{
    #[DbalWrite('INSERT INTO persons VALUES (:personId, :name, :roles)')]
    public function registerUsingMethodParameters(int $personId, string $name): void;
}
