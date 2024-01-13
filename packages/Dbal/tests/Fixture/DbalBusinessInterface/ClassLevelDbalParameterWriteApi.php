<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use Ecotone\Dbal\Attribute\DbalParameter;
use Ecotone\Dbal\Attribute\DbalWrite;
use Ecotone\Messaging\Conversion\MediaType;

#[DbalParameter(name: 'roles', expression: "name === 'Johny' ? ['ROLE_ADMIN'] : []", convertToMediaType: MediaType::APPLICATION_JSON)]
interface ClassLevelDbalParameterWriteApi
{
    #[DbalWrite('INSERT INTO persons VALUES (:personId, :name, :roles)')]
    public function registerUsingMethodParameters(int $personId, string $name): void;
}
