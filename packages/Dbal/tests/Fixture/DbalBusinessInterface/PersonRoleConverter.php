<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use Ecotone\Messaging\Attribute\Converter;

final class PersonRoleConverter
{
    #[Converter]
    public function from(PersonRole $personRole): string
    {
        return $personRole->getRole();
    }

    #[Converter]
    public function to(string $role): PersonRole
    {
        return new PersonRole($role);
    }
}
